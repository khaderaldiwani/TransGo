<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminComplaintLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_complaint_list_and_details_add_localized_display_fields(): void
    {
        $admin = $this->createUser(Role::ROLE_ADMIN, 'complaints-admin@example.com', '0900000401');
        $driver = $this->createUser(Role::ROLE_DRIVER, 'complaints-driver@example.com', '0900000402');

        $complaint = Complaint::create([
            'complaint_code' => 'CMP-LOCALIZED',
            'complainant_id' => $driver->user_id,
            'complainant_role' => Role::ROLE_DRIVER,
            'complaint_type' => 'driver',
            'description' => 'Driver complaint localization test.',
            'status' => 'new',
        ]);

        Sanctum::actingAs($admin);

        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/admin/complaints')
            ->assertOk()
            ->assertJsonPath('data.items.0.complainant_role', 'driver')
            ->assertJsonPath('data.items.0.complainant_role_display', 'سائق')
            ->assertJsonPath('data.items.0.status', 'new')
            ->assertJsonPath('data.items.0.status_display', 'جديدة');

        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/admin/complaints/'.$complaint->complaint_id)
            ->assertOk()
            ->assertJsonPath('data.complaint_info.complaint_type', 'driver')
            ->assertJsonPath('data.complaint_info.complaint_type_display', 'سائق')
            ->assertJsonPath('data.complaint_info.status', 'new')
            ->assertJsonPath('data.complaint_info.status_display', 'جديدة');

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/admin/complaints/'.$complaint->complaint_id)
            ->assertOk()
            ->assertJsonPath('data.complaint_info.complaint_type_display', 'Driver')
            ->assertJsonPath('data.complaint_info.status_display', 'New');
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
