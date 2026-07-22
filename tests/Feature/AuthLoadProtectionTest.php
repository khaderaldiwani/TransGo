<?php

namespace Tests\Feature;

use App\Mail\OtpCodeMail;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthLoadProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_rate_limit_blocks_after_five_attempts(): void
    {
        $email = 'auth-load-login@example.com';
        RateLimiter::clear($email.'|127.0.0.1');
        $this->createUser(Role::ROLE_PASSENGER, $email, '0997000001');

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

    public function test_register_rate_limit_blocks_after_three_attempts(): void
    {
        Mail::fake();
        Role::firstOrCreate(['name' => Role::ROLE_PASSENGER]);

        $phone = '0997000002';
        RateLimiter::clear($phone.'|127.0.0.1');

        $payload = $this->registerPayload('auth-load-register@example.com', $phone);

        $this->postJson('/api/v1/passenger/register', $payload)
            ->assertCreated()
            ->assertJsonPath('success', true);

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $this->postJson('/api/v1/passenger/register', $payload)
                ->assertStatus(422);
        }

        $this->postJson('/api/v1/passenger/register', $payload)
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status_code', 429);
    }

    public function test_verify_otp_rate_limit_blocks_after_five_attempts(): void
    {
        $email = 'auth-load-otp@example.com';
        RateLimiter::clear($email.'|127.0.0.1');
        $this->createUser(Role::ROLE_PASSENGER, $email, '0997000003', User::STATUS_INACTIVE);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/auth/verify-otp', [
                'email' => $email,
                'otp' => '000000',
            ])->assertStatus(400);
        }

        $this->postJson('/api/v1/auth/verify-otp', [
            'email' => $email,
            'otp' => '000000',
        ])
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status_code', 429);
    }

    public function test_duplicate_phone_does_not_create_two_accounts(): void
    {
        Mail::fake();
        Role::firstOrCreate(['name' => Role::ROLE_PASSENGER]);

        $phone = '0997000004';

        $this->postJson('/api/v1/passenger/register', $this->registerPayload('duplicate-one@example.com', $phone))
            ->assertCreated();

        $this->postJson('/api/v1/passenger/register', $this->registerPayload('duplicate-two@example.com', $phone))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(1, User::query()->where('phone', $phone)->count());
    }

    public function test_register_success_json_contract_and_otp_mail_queue_are_preserved(): void
    {
        Mail::fake();
        Role::firstOrCreate(['name' => Role::ROLE_PASSENGER]);

        $response = $this->postJson('/api/v1/passenger/register', $this->registerPayload('auth-load-json@example.com', '0997000005'))
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertSame(['success', 'message', 'data', 'status_code', 'timestamp'], array_keys($response->json()));
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'user',
                'otp',
            ],
            'status_code',
            'timestamp',
        ]);
        Mail::assertQueued(OtpCodeMail::class);
    }

    public function test_login_success_json_contract_is_preserved(): void
    {
        $email = 'auth-load-login-json@example.com';
        $this->createUser(Role::ROLE_PASSENGER, $email, '0997000006');

        $response = $this->postJson('/api/v1/passenger/login', [
            'email' => $email,
            'password' => 'password123',
        ])->assertOk();

        $this->assertSame(['success', 'message', 'data', 'status_code', 'timestamp'], array_keys($response->json()));
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

    private function createUser(string $role, string $email, string $phone, int $status = User::STATUS_ACTIVE): User
    {
        $roleModel = Role::firstOrCreate(['name' => $role]);

        $user = User::create([
            'full_name' => 'Auth Load User',
            'phone' => $phone,
            'email' => $email,
            'password' => Hash::make('password123'),
            'account_status' => $status,
        ]);

        $user->roles()->attach($roleModel->id);

        return $user->fresh('roles');
    }

    private function registerPayload(string $email, string $phone): array
    {
        return [
            'name' => 'Auth Load Passenger',
            'phone' => $phone,
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];
    }
}
