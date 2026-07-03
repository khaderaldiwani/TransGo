<?php

namespace App\Services;

use App\Models\TripStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

class TripStatusService
{
    private const CACHE_TTL_MINUTES = 30;

    public function list(): array
    {
        if (app()->runningUnitTests()) {
            return $this->buildList();
        }

        return Cache::store('file')->remember(
            $this->cacheKey(),
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn () => $this->buildList()
        );
    }

    private function buildList(): array
    {
        $statuses = $this->resolveStatuses()
            ->prepend([
                'id' => null,
                'key' => 'all',
                'name' => 'الكل',
                'description' => 'عرض جميع حالات الرحلة.',
                'is_final' => false,
                'display_order' => 0,
            ]);

        return [
            'items' => $statuses
                ->map(fn (array $status, int $index) => [
                    'id' => $status['id'] ?? ($status['key'] === 'all' ? 0 : $index + 1),
                    'key' => $status['key'],
                    'name' => $status['name'],
                    'description' => $status['description'],
                    'is_final' => (bool) $status['is_final'],
                    'display_order' => (int) $status['display_order'],
                    'color' => $this->resolveColor($status['key']),
                ])
                ->values(),
        ];
    }

    private function cacheKey(): string
    {
        $fingerprint = TripStatus::query()
            ->selectRaw('COUNT(*) as rows_count, MAX(status_id) as max_id, MAX(updated_at) as max_updated_at')
            ->first();

        return implode(':', [
            'api',
            'trip_statuses',
            $fingerprint?->rows_count ?? 0,
            $fingerprint?->max_id ?? 0,
            $fingerprint?->max_updated_at ?? 'none',
        ]);
    }

    private function resolveStatuses(): Collection
    {
        $defaults = collect($this->defaultStatuses())
            ->keyBy('key');

        try {
            $storedStatuses = TripStatus::query()
                ->where('is_active', true)
                ->orderBy('display_order')
                ->get(['status_id', 'status_key'])
                ->keyBy('status_key');

            if ($storedStatuses->isEmpty()) {
                return $defaults
                    ->sortBy('display_order')
                    ->values();
            }

            return $defaults
                ->map(function (array $default) use ($storedStatuses) {
                    /** @var TripStatus|null $storedStatus */
                    $storedStatus = $storedStatuses->get($default['key']);

                    return [
                        'id' => $storedStatus?->status_id,
                        'key' => $default['key'],
                        'name' => $default['name'],
                        'description' => $default['description'],
                        'is_final' => $default['is_final'],
                        'display_order' => $default['display_order'],
                    ];
                })
                ->sortBy('display_order')
                ->values();
        } catch (Throwable $exception) {
            report($exception);

            return $defaults
                ->sortBy('display_order')
                ->values();
        }
    }

    private function defaultStatuses(): array
    {
        return [
            [
                'key' => TripStatus::PENDING,
                'name' => 'قيد الانتظار',
                'description' => 'الرحلة بانتظار الانطلاق أو التأكيد.',
                'is_final' => false,
                'display_order' => 1,
            ],
            [
                'key' => TripStatus::ACTIVE,
                'name' => 'نشطة',
                'description' => 'الرحلة قيد التنفيذ حالياً.',
                'is_final' => false,
                'display_order' => 2,
            ],
            [
                'key' => TripStatus::COMPLETED,
                'name' => 'منجزة',
                'description' => 'تم إنهاء الرحلة بنجاح من قبل السائق.',
                'is_final' => true,
                'display_order' => 3,
            ],
            [
                'key' => TripStatus::CANCELED,
                'name' => 'ملغاة',
                'description' => 'تم إلغاء الرحلة.',
                'is_final' => true,
                'display_order' => 4,
            ],
        ];
    }

    private function resolveColor(string $statusKey): string
    {
        return match ($statusKey) {
            'all' => '#334155',
            TripStatus::PENDING => '#f59e0b',
            TripStatus::ACTIVE => '#0ea5e9',
            TripStatus::COMPLETED => '#10b981',
            TripStatus::AUTO_COMPLETED => '#14b8a6',
            TripStatus::CANCELED => '#ef4444',
            default => '#64748b',
        };
    }
}
