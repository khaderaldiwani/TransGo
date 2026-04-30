<?php

namespace Tests\Feature;

use App\Models\BookingStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingStatusApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_active_booking_statuses_ordered_for_frontend(): void
    {
        BookingStatus::create([
            'status_key' => 'pending',
            'status_name' => 'قيد الانتظار',
            'description' => 'Pending',
            'is_final' => false,
            'display_order' => 1,
            'is_active' => true,
        ]);

        BookingStatus::create([
            'status_key' => 'accepted',
            'status_name' => 'مقبول',
            'description' => 'Accepted',
            'is_final' => false,
            'display_order' => 2,
            'is_active' => true,
        ]);

        BookingStatus::create([
            'status_key' => 'canceled',
            'status_name' => 'ملغى',
            'description' => 'Canceled',
            'is_final' => true,
            'display_order' => 4,
            'is_active' => true,
        ]);

        BookingStatus::create([
            'status_key' => 'archived',
            'status_name' => 'مؤرشف',
            'description' => 'Archived',
            'is_final' => true,
            'display_order' => 6,
            'is_active' => false,
        ]);

        $this->getJson('/api/v1/booking-statuses')
            ->assertOk()
            ->assertJsonPath('data.items.0.key', 'pending')
            ->assertJsonPath('data.items.0.color', '#f59e0b')
            ->assertJsonPath('data.items.0.name', 'قيد الانتظار')
            ->assertJsonPath('data.items.1.key', 'accepted')
            ->assertJsonPath('data.items.1.color', '#10b981')
            ->assertJsonFragment(['key' => 'canceled'])
            ->assertJsonMissing(['key' => 'archived']);
    }

    public function test_it_returns_default_booking_statuses_when_database_table_is_empty(): void
    {
        $this->getJson('/api/v1/booking-statuses')
            ->assertOk()
            ->assertJsonPath('data.items.0.key', 'pending')
            ->assertJsonPath('data.items.0.name', 'قيد الانتظار')
            ->assertJsonPath('data.items.1.key', 'accepted')
            ->assertJsonPath('data.items.2.key', 'rejected')
            ->assertJsonPath('data.items.3.key', 'canceled')
            ->assertJsonPath('data.items.4.key', 'completed');
    }
}
