<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test user registration with role assignment.
     */
    public function test_user_registration_assigns_passenger_role(): void
    {
        // Seed roles
        \App\Models\Role::create(['name' => 'passenger']);
        \App\Models\Role::create(['name' => 'driver']);
        \App\Models\Role::create(['name' => 'admin']);
        \App\Models\Role::create(['name' => 'employee']);

        // Ensure passenger role exists
        $role = Role::where('name', 'passenger')->first();
        $this->assertNotNull($role, 'Passenger role should exist');

        // Simulate registration data
        $data = [
            'name' => 'Test User',
            'phone' => '0501234567',
            'password' => 'password123',
        ];

        // Call the register method via service
        $authService = app(\App\Services\AuthService::class);
        $result = $authService->register($data);

        // Assert user was created
        $this->assertInstanceOf(User::class, $result['user']);
        $this->assertEquals('Test User', $result['user']->full_name);
        $this->assertEquals('0501234567', $result['user']->phone);

        // Assert role was attached
        $this->assertTrue($result['user']->roles->contains($role));

        // Assert OTP was generated
        $this->assertNotNull($result['otp']);
    }
}