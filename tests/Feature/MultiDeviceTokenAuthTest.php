<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MultiDeviceTokenAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_multiple_logins_keep_all_tokens_until_each_token_logs_out(): void
    {
        $user = $this->createPassenger();

        $firstLogin = $this->postJson('/api/v1/passenger/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $secondLogin = $this->postJson('/api/v1/passenger/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $firstToken = $firstLogin->json('data.token');
        $secondToken = $secondLogin->json('data.token');

        $this->assertNotEmpty($firstToken);
        $this->assertNotEmpty($secondToken);
        $this->assertNotSame($firstToken, $secondToken);
        $this->assertDatabaseCount('personal_access_tokens', 2);

        $this->withToken($firstToken)
            ->getJson('/api/user')
            ->assertOk();

        $this->withToken($secondToken)
            ->getJson('/api/user')
            ->assertOk();

        $this->withToken($firstToken)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => (int) str($firstToken)->before('|')->toString(),
        ]);

        $this->withToken($secondToken)
            ->getJson('/api/user')
            ->assertOk();

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => (int) str($secondToken)->before('|')->toString(),
        ]);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    private function createPassenger(): User
    {
        $role = Role::firstOrCreate(['name' => Role::ROLE_PASSENGER]);

        $user = User::create([
            'full_name' => 'Multi Device Passenger',
            'phone' => '0998000001',
            'email' => 'multi-device-passenger@example.com',
            'password' => Hash::make('password123'),
            'account_status' => User::STATUS_ACTIVE,
        ]);
        $user->roles()->attach($role->id);

        return $user->fresh('roles');
    }
}
