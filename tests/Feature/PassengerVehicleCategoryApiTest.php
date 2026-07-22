<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\VehicleCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PassengerVehicleCategoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_passenger_can_list_active_vehicle_categories_with_id_and_name_only(): void
    {
        $passenger = $this->createPassengerUser();

        VehicleCategory::where('name', 'كومفورت مكيف')->update(['is_active' => false]);

        Sanctum::actingAs($passenger);

        $response = $this->getJson('/api/v1/passenger/vehicle-categories')
            ->assertOk()
            ->assertJsonPath('data.items.0.category_id', 1)
            ->assertJsonPath('data.items.0.name', 'كلاسيك فوري')
            ->assertJsonMissingPath('data.items.0.price_per_km')
            ->assertJsonMissingPath('data.items.0.is_active');

        $names = collect($response->json('data.items'))->pluck('name')->all();

        $this->assertNotContains('كومفورت مكيف', $names);
    }

    private function createPassengerUser(): User
    {
        $role = Role::firstOrCreate(['name' => Role::ROLE_PASSENGER]);

        $passenger = User::create([
            'full_name' => 'Vehicle Category Passenger',
            'phone' => '0999999500',
            'email' => 'passenger-vehicle-categories@example.com',
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_SELF,
        ]);

        $passenger->roles()->attach($role->id);

        return $passenger;
    }
}
