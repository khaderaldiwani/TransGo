<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminComplaintReportLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_complaint_report_adds_localized_display_fields_without_changing_existing_values(): void
    {
        $admin = $this->createUser(Role::ROLE_ADMIN, 'report-admin@example.com', '0900000201');
        $passenger = $this->createUser(Role::ROLE_PASSENGER, 'report-passenger@example.com', '0900000202');

        Complaint::create([
            'complaint_code' => 'CMP-REPORT-LOCALIZED',
            'complainant_id' => $passenger->user_id,
            'complainant_role' => Role::ROLE_PASSENGER,
            'complaint_type' => 'payment',
            'description' => 'Payment complaint for localization test.',
            'status' => 'new',
        ]);

        Sanctum::actingAs($admin);

        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/admin/complaints/report')
            ->assertOk()
            ->assertJsonFragment([
                'status' => 'open',
                'status_display' => 'مفتوحة',
            ])
            ->assertJsonFragment([
                'type' => 'payment',
                'type_display' => 'دفع',
            ])
            ->assertJsonFragment([
                'complainant_type' => 'passenger',
                'complainant_type_display' => 'راكب',
            ])
            ->assertJsonPath('data.complaints_list.0.complaint_type', 'payment')
            ->assertJsonPath('data.complaints_list.0.complaint_type_display', 'دفع');

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/admin/complaints/report')
            ->assertOk()
            ->assertJsonFragment([
                'status' => 'open',
                'status_display' => 'Open',
            ])
            ->assertJsonFragment([
                'type' => 'payment',
                'type_display' => 'Payment',
            ])
            ->assertJsonPath('data.complaints_list.0.complainant_type_display', 'Passenger')
            ->assertJsonPath('data.complaints_list.0.complaint_type_display', 'Payment');
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
