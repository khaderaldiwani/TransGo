<?php

namespace App\Services;

use App\Models\Governorate;

class GovernorateService
{
    public function list(): array
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
}
