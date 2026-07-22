<?php

namespace App\Services;

use App\Models\Governorate;
use Illuminate\Support\Facades\Cache;

class GovernorateService
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
        return [
            'items' => Governorate::query()
                ->where('is_active', true)
                ->orderBy('governorate_id')
                ->get(['governorate_id', 'name', 'image_url'])
                ->map(fn (Governorate $governorate) => [
                    'id' => $governorate->governorate_id,
                    'name' => $governorate->name,
                    'image_url' => $governorate->image_url,
                ])
                ->values(),
        ];
    }

    private function cacheKey(): string
    {
        $fingerprint = Governorate::query()
            ->selectRaw('COUNT(*) as rows_count, MAX(governorate_id) as max_id, MAX(created_at) as max_created_at')
            ->first();

        return implode(':', [
            'api',
            'governorates',
            $fingerprint?->rows_count ?? 0,
            $fingerprint?->max_id ?? 0,
            $fingerprint?->max_created_at ?? 'none',
        ]);
    }
}
