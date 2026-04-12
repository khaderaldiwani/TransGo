<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAuditLogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_audit_logs_with_filters(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN, 'Main Admin', 'admin@example.com');
        $employee = $this->createBackofficeUser(Role::ROLE_EMPLOYEE, 'Ahmad Employee', 'employee@example.com');
        $secondAdmin = $this->createBackofficeUser(Role::ROLE_ADMIN, 'Sara Admin', 'sara@example.com');

        $matchingLog = AuditLog::create([
            'actor_user_id' => $admin->user_id,
            'action' => 'wallet.topup',
            'entity_type' => 'wallet',
            'entity_id' => 1,
            'old_value' => ['balance' => 100],
            'new_value' => ['balance' => 150],
            'description' => 'Wallet topped up',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        AuditLog::create([
            'actor_user_id' => $employee->user_id,
            'action' => 'employee.updated',
            'entity_type' => 'user',
            'entity_id' => 2,
            'old_value' => ['phone' => '1'],
            'new_value' => ['phone' => '2'],
            'description' => 'Employee updated',
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        AuditLog::create([
            'actor_user_id' => $secondAdmin->user_id,
            'action' => 'wallet.topup',
            'entity_type' => 'wallet',
            'entity_id' => 3,
            'old_value' => ['balance' => 50],
            'new_value' => ['balance' => 75],
            'description' => 'Older wallet topup',
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/audit-logs?actor_name=Main&action=wallet.topup&date_from='.now()->subDays(2)->toDateString().'&date_to='.now()->toDateString());

        $response
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $matchingLog->id)
            ->assertJsonPath('data.data.0.action', 'wallet.topup')
            ->assertJsonPath('data.data.0.action_label', 'Wallet Topup')
            ->assertJsonPath('data.data.0.actor.full_name', 'Main Admin')
            ->assertJsonPath('data.data.0.actor.primary_role', Role::ROLE_ADMIN)
            ->assertJsonPath('data.data.0.entity.label', 'Wallet')
            ->assertJsonPath('data.data.0.created_at.display', $matchingLog->created_at->format('Y-m-d H:i:s'))
            ->assertJsonCount(1, 'data.data');
    }

    public function test_employee_cannot_view_audit_logs(): void
    {
        $employee = $this->createBackofficeUser(Role::ROLE_EMPLOYEE, 'Employee User', 'employee@example.com');

        Sanctum::actingAs($employee);

        $this->getJson('/api/v1/admin/audit-logs')->assertForbidden();
    }

    private function createBackofficeUser(string $roleName, string $fullName, string $email): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);

        $user = User::create([
            'full_name' => $fullName,
            'phone' => '09'.fake()->unique()->numerify('########'),
            'email' => $email,
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_ADMIN,
        ]);

        $user->roles()->attach($role->id);

        return $user;
    }
}
