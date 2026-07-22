<?php

namespace App\Services;

use App\Models\Receipt;
use App\Models\User;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use RuntimeException;

class ReceiptService
{
    public function createForTransaction(WalletTransaction $transaction, array $attributes): Receipt
    {
        $ownerUserId = $attributes['owner_user_id'] ?? null;
        $walletId = $attributes['wallet_id'] ?? $transaction->wallet_id;

        if (! $ownerUserId) {
            throw new RuntimeException('تعذر إنشاء الإيصال لعدم توفر مالك العملية المالية.');
        }

        $receipt = Receipt::create([
            'receipt_number' => $attributes['receipt_number'] ?? $this->generateReceiptNumber(),
            'owner_user_id' => $ownerUserId,
            'wallet_id' => $walletId,
            'related_wallet_transaction_id' => $transaction->transaction_id,
            'related_payment_id' => $attributes['related_payment_id'] ?? null,
            'related_booking_id' => $attributes['related_booking_id'] ?? null,
            'related_trip_id' => $attributes['related_trip_id'] ?? null,
            'commission_rate_id' => $attributes['commission_rate_id'] ?? null,
            'receipt_type' => $attributes['receipt_type'],
            'direction' => $attributes['direction'] ?? null,
            'status' => $attributes['status'],
            'amount' => round((float) ($attributes['amount'] ?? $transaction->amount), 2),
            'counterparty_user_id' => $attributes['counterparty_user_id'] ?? null,
            'counterparty_name' => $attributes['counterparty_name'] ?? null,
            'reason' => $attributes['reason'] ?? null,
            'gross_amount' => $attributes['gross_amount'] ?? null,
            'commission_percentage' => $attributes['commission_percentage'] ?? null,
            'commission_amount' => $attributes['commission_amount'] ?? null,
            'net_amount' => $attributes['net_amount'] ?? null,
            'metadata' => $attributes['metadata'] ?? null,
        ]);

        if ((int) $transaction->related_receipt_id !== (int) $receipt->receipt_id) {
            $transaction->forceFill([
                'related_receipt_id' => $receipt->receipt_id,
            ])->save();
        }

        return $receipt->fresh([
            'owner.roles',
            'counterparty',
            'trip.startGovernorate',
            'trip.endGovernorate',
            'walletTransaction',
        ]);
    }

    public function listForUser(User $actor, array $filters = []): LengthAwarePaginator
    {
        $query = Receipt::query()
            ->with([
                'owner.roles',
                'counterparty',
                'trip.startGovernorate',
                'trip.endGovernorate',
                'walletTransaction',
            ])
            ->where('owner_user_id', $actor->user_id)
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['receipt_type'])) {
            $query->where('receipt_type', $filters['receipt_type']);
        }

        if (! empty($filters['trip_id'])) {
            $query->where('related_trip_id', $filters['trip_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);

            $query->where(function ($builder) use ($search) {
                $builder->where('receipt_number', 'like', "%{$search}%")
                    ->orWhere('counterparty_name', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%");

                if (is_numeric($search)) {
                    $builder->orWhere('related_trip_id', (int) $search);
                }
            });
        }

        return $query->paginate($filters['per_page'] ?? 15)
            ->through(fn (Receipt $receipt) => $this->transformListItem($receipt));
    }

    public function showForUser(int $receiptId, User $actor): array
    {
        $receipt = Receipt::query()
            ->with([
                'owner.roles',
                'counterparty',
                'trip.startGovernorate',
                'trip.endGovernorate',
                'walletTransaction',
                'payment',
                'booking',
            ])
            ->where('owner_user_id', $actor->user_id)
            ->find($receiptId);

        if (! $receipt) {
            throw new RuntimeException('الإيصال المطلوب غير موجود أو لا يتبع لهذا المستخدم.', 404);
        }

        return $this->transformDetails($receipt);
    }

    public function generateReceiptNumber(string $prefix = 'RCT'): string
    {
        do {
            $number = $prefix.'-'.now()->format('YmdHis').'-'.strtoupper(substr(uniqid(), -6));
        } while (Receipt::query()->where('receipt_number', $number)->exists());

        return $number;
    }

    private function transformListItem(Receipt $receipt): array
    {
        return [
            'receipt_id' => $receipt->receipt_id,
            'receipt_number' => $receipt->receipt_number,
            'trip_id' => $receipt->related_trip_id,
            'created_at' => optional($receipt->created_at)->toIso8601String(),
            'amount' => (float) $receipt->amount,
            'status' => [
                'key' => $receipt->status,
                'label' => $this->resolveStatusLabel($receipt->status),
            ],
            'direction' => $receipt->direction,
            'receipt_type' => $receipt->receipt_type,
            'counterparty_name' => $receipt->counterparty_name ?? $receipt->counterparty?->full_name,
            'details_endpoint' => $this->resolveDetailsEndpoint($receipt),
        ];
    }

    private function transformDetails(Receipt $receipt): array
    {
        $trip = $receipt->trip;
        $departureTime = $trip?->departure_time ? Carbon::parse($trip->departure_time) : null;
        $arrivalTime = $departureTime && $trip?->estimated_duration_minutes
            ? $departureTime->copy()->addMinutes((int) $trip->estimated_duration_minutes)
            : null;

        return [
            'receipt_id' => $receipt->receipt_id,
            'receipt_number' => $receipt->receipt_number,
            'general' => [
                'status' => [
                    'key' => $receipt->status,
                    'label' => $this->resolveStatusLabel($receipt->status),
                ],
                'created_at' => optional($receipt->created_at)->toIso8601String(),
                'amount' => (float) $receipt->amount,
                'direction' => $receipt->direction,
                'receipt_type' => $receipt->receipt_type,
                'counterparty_name' => $receipt->counterparty_name ?? $receipt->counterparty?->full_name,
                'reason' => $receipt->reason,
            ],
            'receipt_info' => [
                'gross_amount' => $receipt->gross_amount !== null ? (float) $receipt->gross_amount : null,
                'commission_percentage' => $receipt->commission_percentage !== null ? (float) $receipt->commission_percentage : null,
                'commission_amount' => $receipt->commission_amount !== null ? (float) $receipt->commission_amount : null,
                'net_amount' => $receipt->net_amount !== null ? (float) $receipt->net_amount : null,
                'wallet_transaction' => $receipt->walletTransaction ? [
                    'transaction_id' => $receipt->walletTransaction->transaction_id,
                    'transaction_type' => $receipt->walletTransaction->transaction_type,
                    'reference' => $receipt->walletTransaction->transaction_reference,
                    'status' => $receipt->walletTransaction->status,
                ] : null,
            ],
            'trip_info' => $trip ? [
                'trip_id' => $trip->trip_id,
                'departure_time' => \App\Support\ApiDateTime::toAppIso($departureTime),
                'arrival_time' => \App\Support\ApiDateTime::toAppIso($arrivalTime),
                'start_point' => $trip->startGovernorate?->name,
                'end_point' => $trip->endGovernorate?->name,
            ] : null,
        ];
    }

    private function resolveStatusLabel(string $status): string
    {
        return match ($status) {
            'paid' => 'مدفوع',
            'processing' => 'قيد المعالجة',
            'received' => 'مستلم',
            default => $status,
        };
    }

    private function resolveDetailsEndpoint(Receipt $receipt): string
    {
        $ownerRoles = $receipt->owner?->roles?->pluck('name')->all() ?? [];
        $prefix = in_array('driver', $ownerRoles, true) ? 'driver' : 'passenger';

        return "/api/v1/{$prefix}/receipts/{$receipt->receipt_id}";
    }
}
