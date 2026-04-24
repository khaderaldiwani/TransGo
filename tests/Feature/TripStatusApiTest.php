<?php

namespace Tests\Feature;

use App\Models\TripStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TripStatusApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_active_trip_statuses_ordered_for_frontend(): void
    {
        TripStatus::create([
            'status_key' => TripStatus::PENDING,
            'status_name' => 'قيد الانتظار',
            'description' => 'Pending',
            'is_final' => false,
            'display_order' => 1,
            'is_active' => true,
        ]);

        TripStatus::create([
            'status_key' => TripStatus::ACTIVE,
            'status_name' => 'نشطة',
            'description' => 'Active',
            'is_final' => false,
            'display_order' => 2,
            'is_active' => true,
        ]);

        TripStatus::create([
            'status_key' => TripStatus::CANCELED,
            'status_name' => 'ملغاة',
            'description' => 'Canceled',
            'is_final' => true,
            'display_order' => 5,
            'is_active' => true,
        ]);

        TripStatus::create([
            'status_key' => 'archived',
            'status_name' => 'مؤرشفة',
            'description' => 'Archived',
            'is_final' => true,
            'display_order' => 6,
            'is_active' => false,
        ]);

        $this->getJson('/api/v1/trip-statuses')
            ->assertOk()
            ->assertJsonPath('data.items.0.key', TripStatus::PENDING)
            ->assertJsonPath('data.items.0.color', '#f59e0b')
            ->assertJsonPath('data.items.0.name', 'قيد الانتظار')
            ->assertJsonPath('data.items.1.key', TripStatus::ACTIVE)
            ->assertJsonFragment(['key' => TripStatus::CANCELED])
            ->assertJsonMissing(['key' => TripStatus::AUTO_COMPLETED])
            ->assertJsonMissing(['key' => 'archived']);
    }

    public function test_it_returns_default_trip_statuses_when_database_table_is_empty(): void
    {
        $this->getJson('/api/v1/trip-statuses')
            ->assertOk()
            ->assertJsonPath('data.items.0.key', TripStatus::PENDING)
            ->assertJsonPath('data.items.0.name', 'قيد الانتظار')
            ->assertJsonPath('data.items.1.key', TripStatus::ACTIVE)
            ->assertJsonPath('data.items.2.key', TripStatus::COMPLETED)
            ->assertJsonPath('data.items.3.key', TripStatus::CANCELED)
            ->assertJsonMissing(['key' => TripStatus::AUTO_COMPLETED]);
    }
}
