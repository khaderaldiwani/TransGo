<?php

namespace Tests\Feature;

use App\Models\DriverProfile;
use App\Models\Governorate;
use App\Models\Role;
use App\Models\Trip;
use App\Models\TripStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverFinancialReportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_can_get_own_financial_report_for_date_range(): void
    {
        $driver = $this->createDriver('Naya Driver');
        $otherDriver = $this->createDriver('Other Driver');
        [$damascus, $homs] = $this->createGovernorates();

        $completed = $this->createTripStatus(TripStatus::COMPLETED);
        $autoCompleted = $this->createTripStatus(TripStatus::AUTO_COMPLETED);
        $canceled = $this->createTripStatus(TripStatus::CANCELED);

        $this->createTrip($driver, $damascus, $homs, $completed, [
            'completed_at' => '2026-05-01 12:00:00',
            'gross_revenue_amount' => 2000,
            'commission_percentage' => 5,
            'commission_amount' => 100,
            'net_revenue_amount' => 1900,
        ]);

        $this->createTrip($driver, $damascus, $homs, $autoCompleted, [
            'completed_at' => '2026-05-02 12:00:00',
            'gross_revenue_amount' => 4000,
            'commission_percentage' => 10,
            'commission_amount' => 400,
            'net_revenue_amount' => 3600,
        ]);

        $this->createTrip($driver, $damascus, $homs, $canceled, [
            'completed_at' => '2026-05-02 14:00:00',
            'gross_revenue_amount' => 9999,
            'commission_percentage' => 10,
            'commission_amount' => 999,
            'net_revenue_amount' => 9000,
        ]);

        $this->createTrip($driver, $damascus, $homs, $completed, [
            'completed_at' => '2026-04-30 14:00:00',
            'gross_revenue_amount' => 1000,
            'commission_percentage' => 5,
            'commission_amount' => 50,
            'net_revenue_amount' => 950,
        ]);

        $this->createTrip($otherDriver, $damascus, $homs, $completed, [
            'completed_at' => '2026-05-01 12:00:00',
            'gross_revenue_amount' => 3000,
            'commission_percentage' => 5,
            'commission_amount' => 150,
            'net_revenue_amount' => 2850,
        ]);

        Sanctum::actingAs($driver);

        $response = $this->getJson('/api/v1/driver/reports/financial?date_from=2026-05-01&date_to=2026-05-02');

        $response
            ->assertOk()
            ->assertJsonPath('data.period.date_from', '2026-05-01')
            ->assertJsonPath('data.period.date_to', '2026-05-02')
            ->assertJsonPath('data.summary.completed_trips_count', 2)
            ->assertJsonPath('data.summary.gross_earnings_before_commission', 6000)
            ->assertJsonPath('data.summary.commission_percentage', 8.33)
            ->assertJsonPath('data.summary.commission_amount', 500)
            ->assertJsonPath('data.summary.net_earnings_after_commission', 5500)
            ->assertJsonPath('data.commission_breakdown.0.commission_percentage', 5)
            ->assertJsonPath('data.commission_breakdown.0.completed_trips_count', 1)
            ->assertJsonPath('data.commission_breakdown.1.commission_percentage', 10)
            ->assertJsonPath('data.commission_breakdown.1.completed_trips_count', 1)
            ->assertJsonPath('data.trips.0.gross_earnings_before_commission', 2000)
            ->assertJsonPath('data.trips.1.net_earnings_after_commission', 3600);
    }

    public function test_driver_financial_report_requires_date_range(): void
    {
        $driver = $this->createDriver('Naya Driver');

        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/driver/reports/financial')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date_from', 'date_to']);
    }

    private function createDriver(string $name): User
    {
        $role = Role::firstOrCreate(['name' => Role::ROLE_DRIVER]);

        $driver = User::create([
            'full_name' => $name,
            'phone' => '09'.fake()->unique()->numerify('########'),
            'email' => fake()->unique()->safeEmail(),
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_ADMIN,
        ]);
        $driver->roles()->attach($role->id);

        DriverProfile::create([
            'user_id' => $driver->user_id,
            'address' => 'Damascus',
            'personal_photo' => 'storage/drivers/'.$driver->user_id.'.jpg',
            'approval_status' => 'approved',
        ]);

        return $driver;
    }

    private function createGovernorates(): array
    {
        return [
            Governorate::create([
                'name' => 'Damascus',
                'is_active' => true,
                'created_at' => now(),
            ]),
            Governorate::create([
                'name' => 'Homs',
                'is_active' => true,
                'created_at' => now(),
            ]),
        ];
    }

    private function createTripStatus(string $key): TripStatus
    {
        return TripStatus::firstOrCreate(
            ['status_key' => $key],
            [
                'status_name' => $key,
                'description' => $key,
                'is_final' => in_array($key, [TripStatus::COMPLETED, TripStatus::AUTO_COMPLETED, TripStatus::CANCELED], true),
                'display_order' => 1,
                'is_active' => true,
            ]
        );
    }

    private function createTrip(
        User $driver,
        Governorate $startGovernorate,
        Governorate $endGovernorate,
        TripStatus $status,
        array $overrides = []
    ): Trip {
        return Trip::create(array_merge([
            'driver_id' => $driver->user_id,
            'start_governorate_id' => $startGovernorate->governorate_id,
            'end_governorate_id' => $endGovernorate->governorate_id,
            'departure_time' => '2026-05-01 09:00:00',
            'estimated_duration_minutes' => 90,
            'estimated_distance_km' => 120,
            'total_seats' => 4,
            'available_seats' => 3,
            'allow_shared' => true,
            'allow_private' => true,
            'is_private_booked' => false,
            'shared_price' => 10000,
            'private_price' => 30000,
            'system_calculated_price' => 10000,
            'route_polyline' => 'encoded',
            'status_id' => $status->status_id,
            'created_at' => now(),
        ], $overrides));
    }
}
