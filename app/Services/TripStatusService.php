<?php

namespace App\Services;

use App\Models\TripStatus;
use Illuminate\Support\Collection;
use Throwable;

class TripStatusService
{
    public function list(): array
    {
        $statuses = $this->resolveStatuses();

        return [
            'items' => $statuses
                ->map(fn (array $status, int $index) => [
                    'id' => $status['id'] ?? ($index + 1),
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
            TripStatus::PENDING => '#f59e0b',
            TripStatus::ACTIVE => '#0ea5e9',
            TripStatus::COMPLETED => '#10b981',
            TripStatus::AUTO_COMPLETED => '#14b8a6',
            TripStatus::CANCELED => '#ef4444',
            default => '#64748b',
        };
    }
}
