<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\DriverProfile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityNfrApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_endpoint_is_rate_limited(): void
    {
        $email = 'rate-limit-passenger@example.com';
        RateLimiter::clear($email.'|127.0.0.1');

        $this->createUser(Role::ROLE_PASSENGER, $email, '0991000001');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/passenger/login', [
                'email' => $email,
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $this->postJson('/api/v1/passenger/login', [
            'email' => $email,
            'password' => 'wrong-password',
        ])
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status_code', 429);
    }

    public function test_passenger_cannot_access_driver_route(): void
    {
        $passenger = $this->createUser(Role::ROLE_PASSENGER, 'passenger-role-check@example.com', '0991000002');

        Sanctum::actingAs($passenger);

        $this->getJson('/api/v1/driver/trips')
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_driver_cannot_access_passenger_route(): void
    {
        $driver = $this->createUser(Role::ROLE_DRIVER, 'driver-role-check@example.com', '0991000003');
        DriverProfile::create([
            'user_id' => $driver->user_id,
            'approval_status' => 'approved',
        ]);

        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/passenger/trips')
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_protected_route_requires_token(): void
    {
        $this->getJson('/api/v1/passenger/trips')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('status_code', 401);
    }

    public function test_admin_complaint_status_update_writes_audit_log(): void
    {
        $admin = $this->createUser(Role::ROLE_ADMIN, 'admin-audit-check@example.com', '0991000004');
        $passenger = $this->createUser(Role::ROLE_PASSENGER, 'complaint-passenger@example.com', '0991000005');

        $complaint = Complaint::create([
            'complaint_code' => 'CMP-NFRSEC',
            'complainant_id' => $passenger->user_id,
            'complainant_role' => Role::ROLE_PASSENGER,
            'complaint_type' => 'technical',
            'description' => 'Security NFR audit test complaint.',
            'status' => 'new',
        ]);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/complaints/{$complaint->complaint_id}/status", [
            'status' => 'in_progress',
            'notes' => 'Audit log check.',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->user_id,
            'action' => 'complaint.admin_status_updated',
            'entity_type' => Complaint::class,
            'entity_id' => $complaint->complaint_id,
        ]);
    }

    public function test_success_login_json_contract_keeps_current_shape(): void
    {
        $email = 'json-contract-passenger@example.com';
        $this->createUser(Role::ROLE_PASSENGER, $email, '0991000006');

        $response = $this->postJson('/api/v1/passenger/login', [
            'email' => $email,
            'password' => 'password123',
        ])->assertOk();

        $this->assertSame(
            ['success', 'message', 'data', 'status_code', 'timestamp'],
            array_keys($response->json())
        );

        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'user',
                'token',
                'role',
                'roles',
                'must_change_password',
            ],
            'status_code',
            'timestamp',
        ]);
    }

    private function createUser(string $role, string $email, string $phone): User
    {
        $roleModel = Role::firstOrCreate(['name' => $role]);

        $user = User::create([
            'full_name' => ucfirst($role).' Security User',
            'phone' => $phone,
            'email' => $email,
            'password' => Hash::make('password123'),
            'account_status' => User::STATUS_ACTIVE,
        ]);

        $user->roles()->attach($roleModel->id);

        return $user->fresh('roles');
    }
}
