<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminProfileRoleLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_and_passenger_details_add_localized_role_names(): void
    {
        $admin = $this->createUser(Role::ROLE_ADMIN, 'roles-admin@example.com', '0900000301');
        $employee = $this->createUser(Role::ROLE_EMPLOYEE, 'roles-employee@example.com', '0900000302');
        $passenger = $this->createUser(Role::ROLE_PASSENGER, 'roles-passenger@example.com', '0900000303');

        Sanctum::actingAs($admin);

        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/admin/employees/'.$employee->user_id)
            ->assertOk()
            ->assertJsonPath('data.roles.0.name', 'employee')
            ->assertJsonPath('data.roles.0.name_display', 'موظف');

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/admin/employees/'.$employee->user_id)
            ->assertOk()
            ->assertJsonPath('data.roles.0.name_display', 'Employee');

        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/admin/passengers/'.$passenger->user_id)
            ->assertOk()
            ->assertJsonPath('data.roles.0.name', 'passenger')
            ->assertJsonPath('data.roles.0.name_display', 'راكب');

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/admin/passengers/'.$passenger->user_id)
            ->assertOk()
            ->assertJsonPath('data.roles.0.name_display', 'Passenger');
    }

    private function createUser(string $roleName, string $email, string $phone): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $user = User::create([
            'full_name' => $roleName.' User',
            'phone' => $phone,
            'email' => $email,
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_ADMIN,
        ]);
        $user->roles()->attach($role->id);

        return $user;
    }
}
