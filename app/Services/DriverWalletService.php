<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DriverWalletService
{
    public function __construct(
        protected WalletTransactionService $walletTransactionService,
        protected AuditLogService $auditLogService,
        protected UserNotificationService $userNotificationService,
        protected ReceiptService $receiptService
    ) {
    }

    public function topUp(int $driverId, array $data, User $actor): array
    {
        if (! $actor->hasAnyRole([Role::ROLE_ADMIN])) {
            throw new RuntimeException('Forbidden.', 403);
        }

        $driver = User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', Role::ROLE_DRIVER))
            ->with('wallet')
            ->find($driverId);

        if (! $driver) {
            throw new RuntimeException('السائق غير موجود.', 404);
        }

        return DB::transaction(function () use ($driver, $data, $actor) {
            $wallet = Wallet::firstOrCreate(
                ['user_id' => $driver->user_id],
                ['balance' => 0]
            );

            $latestTopUp = WalletTransaction::query()
                ->where('wallet_id', $wallet->wallet_id)
                ->where('transaction_type', 'topup')
                ->where('status', 'completed')
                ->where('created_at', '>=', now()->subMinute())
                ->latest('created_at')
                ->first();

            if ($latestTopUp) {
                throw new RuntimeException('لا يمكن شحن محفظة هذا السائق أكثر من مرة خلال دقيقة واحدة.', 429);
            }

            $balanceBefore = (float) $wallet->balance;
            $amount = round((float) $data['amount'], 2);
            $balanceAfter = round($balanceBefore + $amount, 2);

            $wallet->update([
                'balance' => $balanceAfter,
            ]);

            $transaction = $this->walletTransactionService->createForWallet($wallet, [
                'amount' => $amount,
                'transaction_type' => 'topup',
                'status' => 'completed',
                'transaction_reference' => $this->walletTransactionService->generateReference(),
                'description' => $data['reason'] ?? null,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'performed_by' => $actor->user_id,
            ]);

            $receipt = $this->receiptService->createForTransaction($transaction, [
                'owner_user_id' => $driver->user_id,
                'wallet_id' => $wallet->wallet_id,
                'receipt_type' => 'wallet_topup',
                'direction' => 'credit',
                'status' => 'received',
                'amount' => $amount,
                'counterparty_user_id' => $actor->user_id,
                'counterparty_name' => $actor->full_name,
                'reason' => $data['reason'] ?? 'شحن محفظة من قبل الأدمن.',
                'metadata' => [
                    'target_role' => Role::ROLE_DRIVER,
                    'performed_by_role' => Role::ROLE_ADMIN,
                ],
            ]);

            $this->auditLogService->log(
                $actor,
                'wallet.topup',
                Wallet::class,
                $wallet->wallet_id,
                [
                    'balance' => $balanceBefore,
                ],
                [
                    'balance' => $balanceAfter,
                    'amount' => $amount,
                    'reason' => $data['reason'] ?? null,
                    'transaction_id' => $transaction->transaction_id,
                    'performed_by' => $actor->user_id,
                    'performed_by_role' => Role::ROLE_ADMIN,
                ],
                "Wallet {$wallet->wallet_id} topped up for driver {$driver->full_name} (ID: {$driver->user_id}) by {$actor->full_name} (ID: {$actor->user_id})."
            );

            $this->userNotificationService->notifyUser($driver->user_id, [
                'title' => 'تم شحن المحفظة',
                'body' => "تمت إضافة مبلغ {$amount} إلى محفظتك. الرصيد الجديد: {$balanceAfter}",
                'notification_type' => 'wallet_topped_up',
                'reference_type' => 'wallet_transaction',
                'reference_id' => $transaction->transaction_id,
                'created_by' => $actor->user_id,
                'target_role' => Role::ROLE_DRIVER,
            ]);

            return [
                'driver' => $driver->fresh(['wallet']),
                'wallet' => $wallet->fresh(),
                'transaction' => $transaction->fresh('performer'),
                'receipt' => $receipt,
            ];
        });
    }

    public function listTopUps(array $filters): LengthAwarePaginator
    {
        $query = WalletTransaction::query()
            ->with(['wallet.user', 'performer'])
            ->where('transaction_type', 'topup')
            ->orderByDesc('created_at');

        return $this->applyTopUpFilters($query, $filters, 'driver_id')
            ->paginate($filters['per_page'] ?? 15)
            ->through(function (WalletTransaction $transaction) {
                $transaction->setAttribute('status_display', $this->statusDisplay($transaction->status));

                return $transaction;
            });
    }

    public function listDriverTopUps(array $filters): LengthAwarePaginator
    {
        $query = WalletTransaction::query()
            ->with(['wallet.user', 'performer'])
            ->where('transaction_type', 'topup')
            ->whereHas('wallet.user.roles', fn ($roleQuery) => $roleQuery->where('name', Role::ROLE_DRIVER))
            ->orderByDesc('created_at');

        return $this->applyTopUpFilters($query, $filters, 'driver_id')->paginate($filters['per_page'] ?? 15);
    }

    private function applyTopUpFilters($query, array $filters, string $userFilterKey)
    {
        if (! empty($filters[$userFilterKey])) {
            $query->whereHas('wallet', fn ($walletQuery) => $walletQuery->where('user_id', $filters[$userFilterKey]));
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);

            $query->where(function ($innerQuery) use ($search) {
                $innerQuery->where('transaction_reference', 'like', "%{$search}%")
                    ->orWhereHas('wallet.user', function ($walletUserQuery) use ($search) {
                        $walletUserQuery->where('full_name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");

                        if (is_numeric($search)) {
                            $walletUserQuery->orWhere('user_id', (int) $search);
                        }
                    });
            });
        }

        return $query;
    }

    private function statusDisplay(string $status): string
    {
        $language = request()->getPreferredLanguage(['ar', 'en']) ?? 'en';

        return match ($language) {
            'ar' => match ($status) {
                'completed' => 'مكتملة',
                'pending' => 'قيد الانتظار',
                'failed' => 'فاشلة',
                'canceled', 'cancelled' => 'ملغاة',
                'reversed' => 'معكوسة',
                default => $status,
            },
            default => match ($status) {
                'completed' => 'Completed',
                'pending' => 'Pending',
                'failed' => 'Failed',
                'canceled' => 'Canceled',
                'cancelled' => 'Cancelled',
                'reversed' => 'Reversed',
                default => $status,
            },
        };
    }
}
