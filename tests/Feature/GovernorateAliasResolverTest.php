<?php

namespace Tests\Feature;

use App\Models\Governorate;
use App\Models\GovernorateAlias;
use App\Services\GovernorateResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GovernorateAliasResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_governorate_from_google_english_alias(): void
    {
        $rifDimashq = Governorate::query()->create([
            'name' => 'ريف دمشق',
            'is_active' => true,
            'created_at' => now(),
        ]);

        GovernorateAlias::query()->create([
            'governorate_id' => $rifDimashq->governorate_id,
            'alias' => 'Rif Dimashq Governorate',
        ]);

        Http::fake([
            '*' => Http::response([
                'results' => [[
                    'address_components' => [[
                        'long_name' => 'Rif Dimashq Governorate',
                        'short_name' => 'Rif Dimashq',
                        'types' => ['administrative_area_level_1', 'political'],
                    ]],
                ]],
            ], 200),
        ]);

        config()->set('services.google_geocoding.api_key', 'fake-key');
        config()->set('services.google_geocoding.base_url', 'https://maps.googleapis.com/maps/api/geocode/json');

        $resolvedId = app(GovernorateResolverService::class)->resolveGovernorateIdFromPoint([
            'latitude' => 33.5700,
            'longitude' => 36.3500,
            'sequence_order' => 1,
        ]);

        $this->assertSame($rifDimashq->governorate_id, $resolvedId);
    }

    public function test_resolves_governorate_from_arabic_alias_with_prefix(): void
    {
        $damascus = Governorate::query()->create([
            'name' => 'دمشق',
            'is_active' => true,
            'created_at' => now(),
        ]);

        GovernorateAlias::query()->create([
            'governorate_id' => $damascus->governorate_id,
            'alias' => 'محافظة دمشق',
        ]);

        Http::fake([
            '*' => Http::response([
                'results' => [[
                    'address_components' => [[
                        'long_name' => 'محافظة دمشق',
                        'short_name' => 'دمشق',
                        'types' => ['administrative_area_level_1', 'political'],
                    ]],
                ]],
            ], 200),
        ]);

        config()->set('services.google_geocoding.api_key', 'fake-key');
        config()->set('services.google_geocoding.base_url', 'https://maps.googleapis.com/maps/api/geocode/json');

        $resolvedId = app(GovernorateResolverService::class)->resolveGovernorateIdFromPoint([
            'latitude' => 33.5138,
            'longitude' => 36.2765,
            'sequence_order' => 1,
        ]);

        $this->assertSame($damascus->governorate_id, $resolvedId);
    }
}
