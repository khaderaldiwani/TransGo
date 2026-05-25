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

class AdminDriverEarningsReportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_get_driver_earnings_report(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN);
        $firstDriver = $this->createDriver('Naya Driver');
        $secondDriver = $this->createDriver('Khader Driver');
        [$damascus, $homs] = $this->createGovernorates();

        $completed = $this->createTripStatus(TripStatus::COMPLETED);
        $autoCompleted = $this->createTripStatus(TripStatus::AUTO_COMPLETED);
        $canceled = $this->createTripStatus(TripStatus::CANCELED);
        $active = $this->createTripStatus(TripStatus::ACTIVE);

        $this->createTrip($firstDriver, $damascus, $homs, $completed, [
            'completed_at' => '2026-05-01 12:00:00',
            'gross_revenue_amount' => 2000,
            'commission_amount' => 100,
            'net_revenue_amount' => 1900,
        ]);

        $this->createTrip($firstDriver, $damascus, $homs, $autoCompleted, [
            'completed_at' => '2026-05-02 12:00:00',
            'gross_revenue_amount' => 4000,
            'commission_amount' => 200,
            'net_revenue_amount' => 3800,
        ]);

        $this->createTrip($secondDriver, $damascus, $homs, $completed, [
            'completed_at' => '2026-05-02 14:00:00',
            'gross_revenue_amount' => 3000,
            'commission_amount' => 150,
            'net_revenue_amount' => 2850,
        ]);

        $this->createTrip($firstDriver, $damascus, $homs, $canceled, [
            'completed_at' => '2026-05-02 16:00:00',
            'gross_revenue_amount' => 9999,
            'commission_amount' => 999,
            'net_revenue_amount' => 9000,
        ]);

        $this->createTrip($firstDriver, $damascus, $homs, $active, [
            'departure_time' => '2026-05-02 16:00:00',
            'gross_revenue_amount' => 9999,
            'commission_amount' => 999,
            'net_revenue_amount' => 9000,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/reports/driver-earnings?date_from=2026-05-01&date_to=2026-05-02');

        $response
            ->assertOk()
            ->assertJsonPath('data.summary.drivers_count', 2)
            ->assertJsonPath('data.summary.total_completed_trips', 3)
            ->assertJsonPath('data.summary.total_trip_income', 9000)
            ->assertJsonPath('data.summary.total_commission_deducted', 450)
            ->assertJsonPath('data.summary.total_net_driver_profit', 8550)
            ->assertJsonPath('data.items.0.driver.full_name', 'Naya Driver')
            ->assertJsonPath('data.items.0.total_trips', 2)
            ->assertJsonPath('data.items.0.total_trip_income', 6000)
            ->assertJsonPath('data.items.0.commission_deducted', 300)
            ->assertJsonPath('data.items.0.net_profit', 5700)
            ->assertJsonPath('data.items.1.driver.full_name', 'Khader Driver')
            ->assertJsonPath('data.items.1.total_trips', 1)
            ->assertJsonPath('data.source.included_trip_statuses.0', TripStatus::COMPLETED)
            ->assertJsonPath('data.source.included_trip_statuses.1', TripStatus::AUTO_COMPLETED);
    }

    public function test_admin_can_filter_driver_earnings_report_by_driver_name(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN);
        $naya = $this->createDriver('Naya Driver');
        $khader = $this->createDriver('Khader Driver');
        [$damascus, $homs] = $this->createGovernorates();
        $completed = $this->createTripStatus(TripStatus::COMPLETED);

        $this->createTrip($naya, $damascus, $homs, $completed, [
            'completed_at' => '2026-05-01 12:00:00',
            'gross_revenue_amount' => 2000,
            'commission_amount' => 100,
            'net_revenue_amount' => 1900,
        ]);

        $this->createTrip($khader, $damascus, $homs, $completed, [
            'completed_at' => '2026-05-01 12:00:00',
            'gross_revenue_amount' => 3000,
            'commission_amount' => 150,
            'net_revenue_amount' => 2850,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/reports/driver-earnings?driver_name=naya&date_from=2026-05-01&date_to=2026-05-01');

        $response
            ->assertOk()
            ->assertJsonPath('data.filters.driver_name', 'naya')
            ->assertJsonPath('data.summary.drivers_count', 1)
            ->assertJsonPath('data.items.0.driver.full_name', 'Naya Driver')
            ->assertJsonPath('data.items.0.net_profit', 1900);
    }

    private function createBackofficeUser(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);

        $user = User::create([
            'full_name' => 'Admin Earnings',
            'phone' => '09'.fake()->unique()->numerify('########'),
            'email' => fake()->unique()->safeEmail(),
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_ADMIN,
        ]);

        $user->roles()->attach($role->id);

        return $user;
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
            'commission_percentage' => 5,
            'created_at' => now(),
        ], $overrides));
    }
}
