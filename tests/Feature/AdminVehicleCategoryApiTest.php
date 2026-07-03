<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\VehicleCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminVehicleCategoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_vehicle_categories(): void
    {
        $admin = $this->createAdminUser();

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/vehicle-categories')
            ->assertOk()
            ->assertJsonPath('data.items.0.name', 'كلاسيك فوري')
            ->assertJsonPath('data.items.0.price_per_km', 87.2);

        $createResponse = $this->postJson('/api/v1/admin/vehicle-categories', [
            'name' => 'اختبار اقتصادي',
            'price_per_km' => 75.5,
            'is_active' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'اختبار اقتصادي')
            ->assertJsonPath('data.price_per_km', 75.5);

        $categoryId = $createResponse->json('data.category_id');

        $this->patchJson('/api/v1/admin/vehicle-categories/'.$categoryId, [
            'price_per_km' => 81.25,
        ])
            ->assertOk()
            ->assertJsonPath('data.category_id', $categoryId)
            ->assertJsonPath('data.price_per_km', 81.25);

        $this->patchJson('/api/v1/admin/vehicle-categories/'.$categoryId.'/toggle-status')
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('vehicle_categories', [
            'category_id' => $categoryId,
            'name' => 'اختبار اقتصادي',
            'price_per_km' => 81.25,
            'is_active' => false,
        ]);
    }

    private function createAdminUser(): User
    {
        $role = Role::firstOrCreate(['name' => Role::ROLE_ADMIN]);

        $admin = User::create([
            'full_name' => 'Vehicle Category Admin',
            'phone' => '0999999600',
            'email' => 'admin-vehicle-categories@example.com',
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_ADMIN,
        ]);

        $admin->roles()->attach($role->id);

        return $admin;
    }
}
