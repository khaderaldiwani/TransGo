<?php

namespace Tests\Feature;

use App\Models\CommissionRate;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCommissionRateApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_commission_rate_and_list_history(): void
    {
        $admin = $this->createAdminUser();

        CommissionRate::create([
            'percentage' => 12,
            'effective_from' => now()->subDay(),
            'is_active' => true,
            'created_by' => $admin->user_id,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/commission-rates', [
            'percentage' => 15,
            'change_reason' => 'رفع النسبة للرحلات الجديدة',
        ])
            ->assertCreated()
            ->assertJsonPath('data.current_rate.percentage', 15)
            ->assertJsonPath('data.current_rate.previous_percentage', 12)
            ->assertJsonPath('data.current_rate.change_reason', 'رفع النسبة للرحلات الجديدة');

        $this->getJson('/api/v1/admin/commission-rates/current')
            ->assertOk()
            ->assertJsonPath('data.current_rate.percentage', 15);

        $this->getJson('/api/v1/admin/commission-rates?search=رفع')
            ->assertOk()
            ->assertJsonPath('data.data.0.percentage', 15)
            ->assertJsonPath('data.data.0.previous_percentage', 12);

        $this->assertDatabaseHas('commission_rates', [
            'percentage' => 12,
            'is_active' => false,
        ]);

        $this->assertDatabaseHas('commission_rates', [
            'percentage' => 15,
            'previous_percentage' => 12,
            'is_active' => true,
            'change_reason' => 'رفع النسبة للرحلات الجديدة',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->user_id,
            'action' => 'commission_rate.updated',
            'entity_type' => CommissionRate::class,
        ]);
    }

    private function createAdminUser(): User
    {
        $role = Role::firstOrCreate(['name' => Role::ROLE_ADMIN]);

        $admin = User::create([
            'full_name' => 'Admin Commission',
            'phone' => '0999999700',
            'email' => 'admin-commission@example.com',
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_ADMIN,
        ]);

        $admin->roles()->attach($role->id);

        return $admin;
    }
}
