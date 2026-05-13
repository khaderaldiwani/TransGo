<?php

namespace Tests\Feature;

use App\Models\Governorate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GovernorateApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_active_governorates_ordered_by_database_id(): void
    {
        $damascus = Governorate::create([
            'name' => 'دمشق',
            'image_url' => 'storage/governorates/damascus.jpg',
            'is_active' => true,
            'created_at' => now(),
        ]);

        Governorate::create([
            'name' => 'محافظة غير فعالة',
            'image_url' => 'storage/governorates/inactive.jpg',
            'is_active' => false,
            'created_at' => now(),
        ]);

        $homs = Governorate::create([
            'name' => 'حمص',
            'image_url' => null,
            'is_active' => true,
            'created_at' => now(),
        ]);

        $this->getJson('/api/v1/governorates')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $damascus->governorate_id)
            ->assertJsonPath('data.items.0.name', 'دمشق')
            ->assertJsonPath('data.items.0.image_url', 'storage/governorates/damascus.jpg')
            ->assertJsonPath('data.items.1.id', $homs->governorate_id)
            ->assertJsonMissing(['name' => 'محافظة غير فعالة']);
    }
}
