<?php

namespace App\Services;

use App\Models\CommissionRate;
use App\Models\Role;
use App\Models\Trip;
use App\Models\User;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CommissionRateService
{
    public function __construct(
        private readonly AuditLogService $auditLogService
    ) {
    }

    public function listRates(array $filters = []): LengthAwarePaginator
    {
        $query = CommissionRate::query()
            ->with('creator.roles')
            ->orderByDesc('effective_from')
            ->orderByDesc('commission_rate_id');

        if (! empty($filters['date_from'])) {
            $query->whereDate('effective_from', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('effective_from', '<=', $filters['date_to']);
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);

            $query->where(function ($builder) use ($search) {
                $builder->where('percentage', 'like', "%{$search}%")
                    ->orWhere('previous_percentage', 'like', "%{$search}%")
                    ->orWhere('change_reason', 'like', "%{$search}%")
                    ->orWhereHas('creator', function ($creatorQuery) use ($search) {
                        $creatorQuery->where('full_name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
            });
        }

        return $query->paginate($filters['per_page'] ?? 15)
            ->through(fn (CommissionRate $rate) => $this->transformRate($rate));
    }

    public function currentRate(): array
    {
        $rate = $this->getCurrentRate();

        return [
            'current_rate' => $rate ? $this->transformRate($rate) : [
                'commission_rate_id' => null,
                'percentage' => 0.0,
                'previous_percentage' => null,
                'effective_from' => null,
                'effective_to' => null,
                'is_active' => false,
                'change_reason' => null,
                'changed_by' => null,
            ],
        ];
    }

    public function createRate(array $data, User $actor): array
    {
        if (! $actor->hasAnyRole([Role::ROLE_ADMIN])) {
            throw new RuntimeException('Forbidden.', 403);
        }

        return DB::transaction(function () use ($data, $actor) {
            $effectiveAt = CarbonImmutable::now();

            $currentRate = CommissionRate::query()
                ->where('is_active', true)
                ->orderByDesc('effective_from')
                ->orderByDesc('commission_rate_id')
                ->lockForUpdate()
                ->first();

            $newPercentage = round((float) $data['percentage'], 2);
            $previousPercentage = $currentRate?->percentage !== null ? (float) $currentRate->percentage : null;

            if ($currentRate && (float) $currentRate->percentage === $newPercentage) {
                throw ValidationException::withMessages([
                    'percentage' => 'نسبة العمولة الحالية مطبقة بالفعل.',
                ]);
            }

            if ($currentRate) {
                $currentRate->update([
                    'is_active' => false,
                    'effective_to' => $effectiveAt,
                ]);
            }

            $rate = CommissionRate::create([
                'percentage' => $newPercentage,
                'previous_percentage' => $previousPercentage,
                'effective_from' => $effectiveAt,
                'effective_to' => null,
                'is_active' => true,
                'change_reason' => $data['change_reason'] ?? null,
                'created_by' => $actor->user_id,
            ]);

            $this->auditLogService->log(
                $actor,
                'commission_rate.updated',
                CommissionRate::class,
                $rate->commission_rate_id,
                [
                    'percentage' => $previousPercentage,
                ],
                [
                    'percentage' => $newPercentage,
                    'effective_from' => $rate->effective_from?->toIso8601String(),
                    'change_reason' => $data['change_reason'] ?? null,
                ],
                "Commission rate updated from {$previousPercentage} to {$newPercentage} by {$actor->full_name}."
            );

            return [
                'current_rate' => $this->transformRate($rate->fresh('creator.roles')),
            ];
        });
    }

    public function getCurrentRate(): ?CommissionRate
    {
        return CommissionRate::query()
            ->with('creator.roles')
            ->where('is_active', true)
            ->where('effective_from', '<=', now())
            ->where(function ($query) {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>', now());
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('commission_rate_id')
            ->first();
    }

    public function resolveSnapshot(): array
    {
        $rate = $this->getCurrentRate();

        return [
            'commission_rate_id' => $rate?->commission_rate_id,
            'percentage' => $rate ? round((float) $rate->percentage, 2) : 0.0,
            'rate' => $rate,
        ];
    }

    public function resolveMaxPotentialRevenue(
        bool $allowShared,
        bool $allowPrivate,
        int $totalSeats,
        ?float $sharedPrice,
        ?float $privatePrice
    ): float {
        $sharedRevenue = $allowShared && $sharedPrice !== null
            ? round($sharedPrice * $totalSeats, 2)
            : 0.0;

        $privateRevenue = $allowPrivate && $privatePrice !== null
            ? round($privatePrice, 2)
            : 0.0;

        return round(max($sharedRevenue, $privateRevenue), 2);
    }

    public function calculateCommissionAmount(float $grossRevenue, float $percentage): float
    {
        return round($grossRevenue * ($percentage / 100), 2);
    }

    public function ensureDriverCanCoverTripCommission(
        User $driver,
        bool $allowShared,
        bool $allowPrivate,
        int $totalSeats,
        ?float $sharedPrice,
        ?float $privatePrice
    ): array {
        $wallet = Wallet::query()->firstOrCreate(
            ['user_id' => $driver->user_id],
            ['balance' => 0]
        );

        $snapshot = $this->resolveSnapshot();
        $maxPotentialRevenue = $this->resolveMaxPotentialRevenue(
            $allowShared,
            $allowPrivate,
            $totalSeats,
            $sharedPrice,
            $privatePrice
        );

        $maxCommissionAmount = $this->calculateCommissionAmount(
            $maxPotentialRevenue,
            (float) $snapshot['percentage']
        );

        if ((float) $wallet->balance < $maxCommissionAmount) {
            throw ValidationException::withMessages([
                'wallet_balance' => "رصيد محفظة السائق غير كافٍ لتغطية العمولة القصوى المتوقعة لهذه الرحلة. المطلوب {$maxCommissionAmount}.",
            ]);
        }

        return [
            'commission_rate_id' => $snapshot['commission_rate_id'],
            'commission_percentage' => (float) $snapshot['percentage'],
            'max_potential_revenue' => $maxPotentialRevenue,
            'max_commission_amount' => $maxCommissionAmount,
            'wallet_balance' => round((float) $wallet->balance, 2),
        ];
    }

    public function previewCommissionSnapshot(
        User $driver,
        bool $allowShared,
        bool $allowPrivate,
        int $totalSeats,
        float $systemCalculatedPrice,
        int $vehicleSeatCapacity
    ): array {
        $wallet = Wallet::query()->firstOrCreate(
            ['user_id' => $driver->user_id],
            ['balance' => 0]
        );

        $snapshot = $this->resolveSnapshot();

        $sharedRevenue = $allowShared
            ? round((round($systemCalculatedPrice / max($vehicleSeatCapacity, 1), 2)) * $totalSeats, 2)
            : 0.0;

        $privateRevenue = $allowPrivate
            ? round($systemCalculatedPrice, 2)
            : 0.0;

        $maxPotentialRevenue = round(max($sharedRevenue, $privateRevenue), 2);
        $maxCommissionAmount = $this->calculateCommissionAmount(
            $maxPotentialRevenue,
            (float) $snapshot['percentage']
        );

        if ((float) $wallet->balance < $maxCommissionAmount) {
            throw ValidationException::withMessages([
                'wallet_balance' => "رصيد محفظة السائق غير كافٍ لتغطية العمولة القصوى المتوقعة لهذه الرحلة. المطلوب {$maxCommissionAmount}.",
            ]);
        }

        return [
            'commission_rate_id' => $snapshot['commission_rate_id'],
            'commission_percentage' => (float) $snapshot['percentage'],
            'max_potential_revenue' => $maxPotentialRevenue,
            'max_commission_amount' => $maxCommissionAmount,
            'wallet_balance' => round((float) $wallet->balance, 2),
        ];
    }

    public function calculateTripGrossRevenue(Trip $trip): float
    {
        $trip->loadMissing(['bookings.status', 'bookings.attendanceStatus']);

        return round(
            $trip->bookings
                ->filter(function ($booking) {
                    $statusKey = $booking->status?->status_key;
                    $attendanceKey = $booking->attendanceStatus?->status_key;

                    return ! in_array($statusKey, ['canceled', 'rejected'], true)
                        && ! ($attendanceKey === 'absent' && $booking->payment_method === 'cash');
                })
                ->sum(fn ($booking) => (float) $booking->total_amount),
            2
        );
    }

    private function transformRate(CommissionRate $rate): array
    {
        return [
            'commission_rate_id' => $rate->commission_rate_id,
            'percentage' => (float) $rate->percentage,
            'previous_percentage' => $rate->previous_percentage !== null ? (float) $rate->previous_percentage : null,
            'effective_from' => optional($rate->effective_from)->toIso8601String(),
            'effective_to' => optional($rate->effective_to)->toIso8601String(),
            'is_active' => (bool) $rate->is_active,
            'change_reason' => $rate->change_reason,
            'changed_by' => $rate->creator ? [
                'user_id' => $rate->creator->user_id,
                'full_name' => $rate->creator->full_name,
                'roles' => $rate->creator->roles?->pluck('name')->values()->all() ?? [],
            ] : null,
        ];
    }
}
