<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Wallet;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PassengerRegistrationWalletTest extends TestCase
{
    use RefreshDatabase;

    public function test_passenger_registration_creates_wallet_automatically(): void
    {
        Role::firstOrCreate(['name' => Role::ROLE_PASSENGER]);

        $result = app(AuthService::class)->register([
            'name' => 'Wallet Passenger',
            'phone' => '0501234567',
            'email' => 'wallet-passenger@example.com',
            'password' => 'password123',
        ], Role::ROLE_PASSENGER);

        $this->assertNotNull($result['user']);
        $this->assertDatabaseHas('wallets', [
            'user_id' => $result['user']->user_id,
            'balance' => 0,
        ]);

        $this->assertInstanceOf(Wallet::class, $result['user']->wallet);
        $this->assertSame('0.00', $result['user']->wallet->balance);
    }
}
