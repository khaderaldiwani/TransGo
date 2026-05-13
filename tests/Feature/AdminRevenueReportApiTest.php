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

class AdminRevenueReportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_get_revenue_report_for_custom_period(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN);
        $driver = $this->createDriver();
        [$damascus, $homs] = $this->createGovernorates();

        $completed = $this->createTripStatus(TripStatus::COMPLETED);
        $autoCompleted = $this->createTripStatus(TripStatus::AUTO_COMPLETED);
        $canceled = $this->createTripStatus(TripStatus::CANCELED);
        $pending = $this->createTripStatus(TripStatus::PENDING);

        $this->createTrip($driver, $damascus, $homs, $completed, [
            'departure_time' => '2026-05-01 09:00:00',
            'completed_at' => '2026-05-01 12:00:00',
            'gross_revenue_amount' => 2000,
            'commission_amount' => 100,
            'net_revenue_amount' => 1900,
            'commission_percentage' => 5,
        ]);

        $this->createTrip($driver, $damascus, $homs, $autoCompleted, [
            'departure_time' => '2026-05-02 09:00:00',
            'completed_at' => '2026-05-02 12:00:00',
            'gross_revenue_amount' => 4000,
            'commission_amount' => 200,
            'net_revenue_amount' => 3800,
            'commission_percentage' => 5,
        ]);

        $this->createTrip($driver, $damascus, $homs, $canceled, [
            'departure_time' => '2026-05-02 09:00:00',
            'completed_at' => '2026-05-02 12:00:00',
            'gross_revenue_amount' => 9999,
            'commission_amount' => 999,
            'net_revenue_amount' => 9000,
            'commission_percentage' => 10,
        ]);

        $this->createTrip($driver, $damascus, $homs, $pending, [
            'departure_time' => '2026-05-02 09:00:00',
            'gross_revenue_amount' => 9999,
            'commission_amount' => 999,
            'net_revenue_amount' => 9000,
            'commission_percentage' => 10,
        ]);

        $this->createTrip($driver, $damascus, $homs, $completed, [
            'departure_time' => '2026-04-30 09:00:00',
            'completed_at' => '2026-04-30 12:00:00',
            'gross_revenue_amount' => 1000,
            'commission_amount' => 50,
            'net_revenue_amount' => 950,
            'commission_percentage' => 5,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/reports/revenue?period=custom&date_from=2026-05-01&date_to=2026-05-02');

        $response
            ->assertOk()
            ->assertJsonPath('data.period.type', 'custom')
            ->assertJsonPath('data.period.date_from', '2026-05-01')
            ->assertJsonPath('data.period.date_to', '2026-05-02')
            ->assertJsonPath('data.period.grouping', 'daily')
            ->assertJsonPath('data.summary.total_revenue', 300)
            ->assertJsonPath('data.summary.completed_trips_count', 2)
            ->assertJsonPath('data.summary.total_commissions', 300)
            ->assertJsonPath('data.summary.total_wallet_deductions', 300)
            ->assertJsonPath('data.summary.total_gross_revenue', 6000)
            ->assertJsonPath('data.summary.average_daily_revenue', 150)
            ->assertJsonPath('data.source.included_trip_statuses.0', TripStatus::COMPLETED)
            ->assertJsonPath('data.source.included_trip_statuses.1', TripStatus::AUTO_COMPLETED)
            ->assertJsonPath('data.chart.0.label', '2026-05-01')
            ->assertJsonPath('data.chart.0.total_revenue', 100)
            ->assertJsonPath('data.chart.0.completed_trips_count', 1)
            ->assertJsonPath('data.chart.1.label', '2026-05-02')
            ->assertJsonPath('data.chart.1.total_revenue', 200)
            ->assertJsonPath('data.chart.1.completed_trips_count', 1);
    }

    public function test_custom_revenue_report_requires_date_range(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/reports/revenue?period=custom')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date_from', 'date_to']);
    }

    private function createBackofficeUser(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);

        $user = User::create([
            'full_name' => 'Revenue Admin',
            'phone' => '09'.fake()->unique()->numerify('########'),
            'email' => fake()->unique()->safeEmail(),
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_ADMIN,
        ]);

        $user->roles()->attach($role->id);

        return $user;
    }

    private function createDriver(): User
    {
        $role = Role::firstOrCreate(['name' => Role::ROLE_DRIVER]);

        $driver = User::create([
            'full_name' => 'Revenue Driver',
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
