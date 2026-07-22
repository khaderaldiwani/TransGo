<?php

namespace App\Services;

use App\Models\Receipt;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use RuntimeException;

class PassengerWalletOverviewService
{
    public function getWalletOverview(User $actor): array
    {
        $wallet = $this->resolvePassengerWallet($actor);

        $transactions = WalletTransaction::query()
            ->with(['receipt', 'performer'])
            ->where('wallet_id', $wallet->wallet_id)
            ->orderByDesc('created_at')
            ->orderByDesc('transaction_id')
            ->limit(100)
            ->get();

        return [
            'wallet' => [
                'wallet_id' => $wallet->wallet_id,
                'current_balance' => (float) $wallet->balance,
                'recent_transactions_count' => $transactions->count(),
                'recent_transactions' => $transactions
                    ->map(fn (WalletTransaction $transaction) => $this->transformTransactionCard($transaction))
                    ->values(),
            ],
        ];
    }

    public function listTransactions(User $actor, array $filters = []): LengthAwarePaginator
    {
        $wallet = $this->resolvePassengerWallet($actor);

        $query = WalletTransaction::query()
            ->with(['receipt', 'performer'])
            ->where('wallet_id', $wallet->wallet_id)
            ->orderByDesc('created_at')
            ->orderByDesc('transaction_id');

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);

            $query->where(function ($builder) use ($search) {
                $builder->where('transaction_reference', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('receipt', function ($receiptQuery) use ($search) {
                        $receiptQuery->where('reason', 'like', "%{$search}%")
                            ->orWhere('counterparty_name', 'like', "%{$search}%")
                            ->orWhere('receipt_number', 'like', "%{$search}%");
                    });
            });
        }

        return $query
            ->paginate($filters['per_page'] ?? 100)
            ->through(fn (WalletTransaction $transaction) => $this->transformTransactionCard($transaction));
    }

    public function showTransactionDetails(int $transactionId, User $actor): array
    {
        $wallet = $this->resolvePassengerWallet($actor);

        $transaction = WalletTransaction::query()
            ->with([
                'receipt.trip.startGovernorate',
                'receipt.trip.endGovernorate',
                'receipt.counterparty',
                'performer',
                'booking',
            ])
            ->where('wallet_id', $wallet->wallet_id)
            ->find($transactionId);

        if (! $transaction) {
            throw new RuntimeException('العملية المطلوبة غير موجودة أو لا تتبع لهذه المحفظة.', 404);
        }

        $receipt = $transaction->receipt;
        $trip = $receipt?->trip;

        return [
            'card' => $this->transformTransactionCard($transaction),
            'details' => [
                'transaction_type' => $transaction->transaction_type,
                'transaction_reference' => $transaction->transaction_reference,
                'amount' => (float) $transaction->amount,
                'balance_before' => (float) $transaction->balance_before,
                'balance_after' => (float) $transaction->balance_after,
                'description' => $transaction->description,
                'receipt_number' => $receipt?->receipt_number,
                'direction' => $receipt?->direction,
                'trip' => $trip ? [
                    'trip_id' => $trip->trip_id,
                    'start_point' => $trip->startGovernorate?->name,
                    'end_point' => $trip->endGovernorate?->name,
                    'departure_time' => \App\Support\ApiDateTime::toAppIso($trip->departure_time),
                ] : null,
                'booking' => $transaction->booking ? [
                    'booking_id' => $transaction->booking->booking_id,
                    'booking_code' => $transaction->booking->booking_code,
                ] : null,
            ],
        ];
    }

    private function transformTransactionCard(WalletTransaction $transaction): array
    {
        $receipt = $transaction->receipt;

        return [
            'transaction_id' => $transaction->transaction_id,
            'title' => $this->resolveTitle($transaction, $receipt),
            'formatted_amount' => $this->formatAmount((float) $transaction->amount, $this->isCreditTransaction($transaction)),
            'status_label' => $this->resolveStatusLabel((string) $transaction->status),
            'actor_name' => $receipt?->counterparty_name ?: $transaction->performer?->full_name,
            'reason' => $receipt?->reason ?: $transaction->description,
            'receipt_id' => $receipt?->receipt_id,
            'details_endpoint' => '/api/v1/passenger/wallet/transactions/'.$transaction->transaction_id,
            'created_at' => optional($transaction->created_at)->toIso8601String(),
        ];
    }

    private function resolveTitle(WalletTransaction $transaction, ?Receipt $receipt): string
    {
        return match ($receipt?->receipt_type) {
            'wallet_topup' => 'شحن محفظة',
            'booking_payment' => 'دفع حجز إلكتروني',
            'booking_refund' => 'استرداد بعد إلغاء الحجز',
            'booking_rejection_refund' => 'استرداد بعد رفض الحجز',
            'trip_cancellation_refund' => 'استرداد بعد إلغاء الرحلة',
            default => match ($transaction->transaction_type) {
                'topup' => 'شحن محفظة',
                'credit' => 'إضافة رصيد',
                'refund' => 'استرداد',
                'debit' => 'خصم رصيد',
                'commission' => 'خصم عمولة',
                'adjustment' => 'تعديل على الرصيد',
                default => 'عملية مالية',
            },
        };
    }

    private function resolveStatusLabel(string $status): string
    {
        return match ($status) {
            'completed' => 'مكتملة',
            'pending' => 'قيد المعالجة',
            'failed' => 'فاشلة',
            default => $status,
        };
    }

    private function isCreditTransaction(WalletTransaction $transaction): bool
    {
        return in_array($transaction->transaction_type, ['topup', 'credit', 'refund'], true);
    }

    private function formatAmount(float $amount, bool $isCredit): string
    {
        $formatted = number_format($amount, fmod($amount, 1.0) === 0.0 ? 0 : 2, '.', ',');

        return ($isCredit ? '+' : '-').$formatted;
    }

    private function resolvePassengerWallet(User $actor): Wallet
    {
        return Wallet::query()->firstOrCreate(
            ['user_id' => $actor->user_id],
            ['balance' => 0]
        );
    }
}
