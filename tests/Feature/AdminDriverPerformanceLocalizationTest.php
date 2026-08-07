<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDriverPerformanceLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_performance_classification_has_a_localized_display_without_changing_its_value(): void
    {
        $adminRole = Role::firstOrCreate(['name' => Role::ROLE_ADMIN]);
        $driverRole = Role::firstOrCreate(['name' => Role::ROLE_DRIVER]);

        $admin = User::create([
            'full_name' => 'Admin User',
            'phone' => '0900000101',
            'email' => 'performance-admin@example.com',
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_ADMIN,
        ]);
        $admin->roles()->attach($adminRole->id);

        $driver = User::create([
            'full_name' => 'Driver User',
            'phone' => '0900000102',
            'email' => 'performance-driver@example.com',
            'password' => bcrypt('password'),
            'rating' => 4.5,
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_ADMIN,
        ]);
        $driver->roles()->attach($driverRole->id);

        Sanctum::actingAs($admin);

        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/admin/driver-performance/report?driver_id='.$driver->user_id)
            ->assertOk()
            ->assertJsonPath('data.driver_reports.0.summary.performance_classification', 'Good')
            ->assertJsonPath('data.driver_reports.0.summary.performance_classification_display', 'جيد');

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/admin/driver-performance/report?driver_id='.$driver->user_id)
            ->assertOk()
            ->assertJsonPath('data.driver_reports.0.summary.performance_classification', 'Good')
            ->assertJsonPath('data.driver_reports.0.summary.performance_classification_display', 'Good');
    }
}
