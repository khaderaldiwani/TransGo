<?php

namespace App\Services;

use App\Models\BookingStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

class BookingStatusService
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
                'description' => 'عرض جميع حالات الحجز.',
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
        $fingerprint = BookingStatus::query()
            ->selectRaw('COUNT(*) as rows_count, MAX(status_id) as max_id, MAX(updated_at) as max_updated_at')
            ->first();

        return implode(':', [
            'api',
            'booking_statuses',
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
            $storedStatuses = BookingStatus::query()
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
                    /** @var BookingStatus|null $storedStatus */
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
                'key' => 'accepted',
                'name' => 'مقبول',
                'description' => 'تم قبول الحجز وتأكيده.',
                'is_final' => false,
                'display_order' => 2,
            ],
            [
                'key' => 'rejected',
                'name' => 'مرفوض',
                'description' => 'تم رفض الحجز مع توثيق السبب عند توفره.',
                'is_final' => true,
                'display_order' => 3,
            ],
            [
                'key' => 'canceled',
                'name' => 'ملغى',
                'description' => 'تم إلغاء الحجز من الراكب أو النظام أو الإدارة.',
                'is_final' => true,
                'display_order' => 4,
            ],
            [
                'key' => 'completed',
                'name' => 'منتهي',
                'description' => 'تم تنفيذ الرحلة واكتمل الحجز بنجاح.',
                'is_final' => true,
                'display_order' => 5,
            ],
        ];
    }

    private function resolveColor(string $statusKey): string
    {
        return match ($statusKey) {
            'all' => '#334155',
            'accepted' => '#10b981',
            'rejected' => '#ef4444',
            'canceled' => '#64748b',
            'completed' => '#0ea5e9',
            default => '#94a3b8',
        };
    }
}
