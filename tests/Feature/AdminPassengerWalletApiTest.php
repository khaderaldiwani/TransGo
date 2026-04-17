<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPassengerWalletApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_top_up_passenger_wallet_and_create_related_records(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN, 'Admin User', 'admin-passenger@example.com');
        $passenger = $this->createPassenger('Passenger Wallet', 'passenger-wallet@example.com');

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/v1/admin/passengers/{$passenger->user_id}/wallet/top-up", [
            'amount' => 175.25,
            'reason' => 'Manual recharge by admin',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.wallet.balance', '175.25')
            ->assertJsonPath('data.transaction.transaction_type', 'topup')
            ->assertJsonPath('data.transaction.status', 'completed');

        $this->assertDatabaseHas('wallets', [
            'user_id' => $passenger->user_id,
            'balance' => 175.25,
        ]);

        $walletId = Wallet::query()->where('user_id', $passenger->user_id)->value('wallet_id');

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $walletId,
            'amount' => 175.25,
            'transaction_type' => 'topup',
            'status' => 'completed',
            'performed_by' => $admin->user_id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->user_id,
            'action' => 'wallet.topup',
            'entity_type' => Wallet::class,
            'entity_id' => $walletId,
        ]);

        $this->assertDatabaseHas('notifications', [
            'notification_type' => 'wallet_topped_up',
            'target_role' => Role::ROLE_PASSENGER,
            'created_by' => $admin->user_id,
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $passenger->user_id,
            'is_sent' => true,
        ]);
    }

    public function test_admin_top_up_creates_wallet_for_passenger_if_missing(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN, 'Admin User', 'admin-create-wallet@example.com');
        $passenger = $this->createPassenger('Passenger No Wallet', 'passenger-no-wallet@example.com', false);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/passengers/{$passenger->user_id}/wallet/top-up", [
            'amount' => 50,
        ])->assertOk();

        $this->assertDatabaseHas('wallets', [
            'user_id' => $passenger->user_id,
            'balance' => 50,
        ]);
    }

    public function test_employee_cannot_top_up_passenger_wallet(): void
    {
        $employee = $this->createBackofficeUser(Role::ROLE_EMPLOYEE, 'Employee User', 'employee-passenger@example.com');
        $passenger = $this->createPassenger('Passenger Wallet', 'passenger-wallet-employee@example.com');

        Sanctum::actingAs($employee);

        $this->postJson("/api/v1/admin/passengers/{$passenger->user_id}/wallet/top-up", [
            'amount' => 50,
        ])->assertForbidden();
    }

    public function test_admin_cannot_top_up_passenger_wallet_with_negative_amount(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN, 'Admin User', 'admin-negative-passenger@example.com');
        $passenger = $this->createPassenger('Passenger Wallet', 'passenger-negative@example.com');

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/passengers/{$passenger->user_id}/wallet/top-up", [
            'amount' => -10,
        ])->assertStatus(422);
    }

    public function test_admin_can_filter_passenger_wallet_topups_by_passenger_and_date(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN, 'Admin User', 'admin-filter-passenger@example.com');
        $firstPassenger = $this->createPassenger('First Passenger', 'first-passenger@example.com');
        $secondPassenger = $this->createPassenger('Second Passenger', 'second-passenger@example.com');

        $firstTransaction = WalletTransaction::create([
            'wallet_id' => $firstPassenger->wallet->wallet_id,
            'amount' => 120,
            'transaction_type' => 'topup',
            'status' => 'completed',
            'transaction_reference' => 'PAX-FIRST',
            'description' => 'First topup',
            'balance_before' => 0,
            'balance_after' => 120,
            'performed_by' => $admin->user_id,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        WalletTransaction::create([
            'wallet_id' => $secondPassenger->wallet->wallet_id,
            'amount' => 220,
            'transaction_type' => 'topup',
            'status' => 'completed',
            'transaction_reference' => 'PAX-SECOND',
            'description' => 'Second topup',
            'balance_before' => 0,
            'balance_after' => 220,
            'performed_by' => $admin->user_id,
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/v1/admin/passenger-wallet-topups?passenger_id={$firstPassenger->user_id}&date_from=".now()->subDays(2)->toDateString().'&date_to='.now()->toDateString());

        $response
            ->assertOk()
            ->assertJsonPath('data.data.0.transaction_id', $firstTransaction->transaction_id)
            ->assertJsonCount(1, 'data.data');
    }

    public function test_admin_can_search_passengers_by_id_and_receive_wallet_data(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN, 'Admin User', 'admin-search-passenger@example.com');
        $passenger = $this->createPassenger('Searchable Passenger', 'search-passenger@example.com');

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/passengers?search='.$passenger->user_id);

        $response
            ->assertOk()
            ->assertJsonPath('data.data.0.user_id', $passenger->user_id)
            ->assertJsonPath('data.data.0.wallet.balance', '0.00');
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

    private function createPassenger(string $fullName, string $email, bool $withWallet = true): User
    {
        $passengerRole = Role::firstOrCreate(['name' => Role::ROLE_PASSENGER]);

        $passenger = User::create([
            'full_name' => $fullName,
            'phone' => '09'.fake()->unique()->numerify('########'),
            'email' => $email,
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_SELF,
        ]);

        $passenger->roles()->attach($passengerRole->id);

        if ($withWallet) {
            Wallet::create([
                'user_id' => $passenger->user_id,
                'balance' => 0,
            ]);
        }

        return $passenger->fresh('wallet');
    }
}
