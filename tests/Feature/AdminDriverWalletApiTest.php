<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDriverWalletApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_top_up_driver_wallet_and_create_related_records(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN, 'Admin User', 'admin@example.com');
        $driver = $this->createDriver('Driver Wallet', 'driver@example.com');

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/v1/admin/drivers/{$driver->user_id}/wallet/top-up", [
            'amount' => 150.75,
            'reason' => 'Manual recharge by admin',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.wallet.balance', '150.75')
            ->assertJsonPath('data.transaction.transaction_type', 'topup')
            ->assertJsonPath('data.transaction.status', 'completed');

        $this->assertDatabaseHas('wallets', [
            'user_id' => $driver->user_id,
            'balance' => 150.75,
        ]);

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $driver->wallet->wallet_id,
            'amount' => 150.75,
            'transaction_type' => 'topup',
            'status' => 'completed',
            'performed_by' => $admin->user_id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->user_id,
            'action' => 'wallet.topup',
            'entity_type' => Wallet::class,
            'entity_id' => $driver->wallet->wallet_id,
        ]);

        $this->assertDatabaseHas('notifications', [
            'notification_type' => 'wallet_topped_up',
            'target_role' => Role::ROLE_DRIVER,
            'created_by' => $admin->user_id,
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $driver->user_id,
            'is_sent' => true,
        ]);
    }

    public function test_employee_cannot_top_up_driver_wallet(): void
    {
        $employee = $this->createBackofficeUser(Role::ROLE_EMPLOYEE, 'Employee User', 'employee@example.com');
        $driver = $this->createDriver('Driver Wallet', 'driver@example.com');

        Sanctum::actingAs($employee);

        $this->postJson("/api/v1/admin/drivers/{$driver->user_id}/wallet/top-up", [
            'amount' => 50,
        ])->assertForbidden();
    }

    public function test_admin_cannot_top_up_driver_wallet_with_negative_amount(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN, 'Admin User', 'admin@example.com');
        $driver = $this->createDriver('Driver Wallet', 'driver@example.com');

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/drivers/{$driver->user_id}/wallet/top-up", [
            'amount' => -10,
        ])->assertStatus(422);
    }

    public function test_admin_can_filter_wallet_topups_by_driver_and_date(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN, 'Admin User', 'admin@example.com');
        $firstDriver = $this->createDriver('First Driver', 'first-driver@example.com');
        $secondDriver = $this->createDriver('Second Driver', 'second-driver@example.com');

        $firstTransaction = WalletTransaction::create([
            'wallet_id' => $firstDriver->wallet->wallet_id,
            'amount' => 120,
            'transaction_type' => 'topup',
            'status' => 'completed',
            'transaction_reference' => 'TOPUP-FIRST',
            'description' => 'First topup',
            'balance_before' => 0,
            'balance_after' => 120,
            'performed_by' => $admin->user_id,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        WalletTransaction::create([
            'wallet_id' => $secondDriver->wallet->wallet_id,
            'amount' => 220,
            'transaction_type' => 'topup',
            'status' => 'completed',
            'transaction_reference' => 'TOPUP-SECOND',
            'description' => 'Second topup',
            'balance_before' => 0,
            'balance_after' => 220,
            'performed_by' => $admin->user_id,
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/v1/admin/wallet-topups?driver_id={$firstDriver->user_id}&date_from=".now()->subDays(2)->toDateString().'&date_to='.now()->toDateString());

        $response
            ->assertOk()
            ->assertJsonPath('data.data.0.transaction_id', $firstTransaction->transaction_id)
            ->assertJsonCount(1, 'data.data');
    }

    public function test_admin_cannot_top_up_same_driver_more_than_once_within_a_minute(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN, 'Admin User', 'admin@example.com');
        $driver = $this->createDriver('Rate Limited Driver', 'rate-limited-driver@example.com');

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/drivers/{$driver->user_id}/wallet/top-up", [
            'amount' => 100,
            'reason' => 'First topup',
        ])->assertOk();

        $this->postJson("/api/v1/admin/drivers/{$driver->user_id}/wallet/top-up", [
            'amount' => 50,
            'reason' => 'Second topup',
        ])
            ->assertStatus(429)
            ->assertJsonPath('message', 'لا يمكن شحن محفظة هذا السائق أكثر من مرة خلال دقيقة واحدة.');

        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseHas('wallets', [
            'user_id' => $driver->user_id,
            'balance' => 100,
        ]);
    }

    public function test_admin_can_search_drivers_by_id_and_receive_wallet_data(): void
    {
        $admin = $this->createBackofficeUser(Role::ROLE_ADMIN, 'Admin User', 'admin@example.com');
        $driver = $this->createDriver('Searchable Driver', 'search-driver@example.com', 88.50);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/drivers?search='.$driver->user_id);

        $response
            ->assertOk()
            ->assertJsonPath('data.data.0.user_id', $driver->user_id)
            ->assertJsonPath('data.data.0.wallet.balance', '88.50');
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

    private function createDriver(string $fullName, string $email, float $balance = 0): User
    {
        $driverRole = Role::firstOrCreate(['name' => Role::ROLE_DRIVER]);

        $driver = User::create([
            'full_name' => $fullName,
            'phone' => '09'.fake()->unique()->numerify('########'),
            'email' => $email,
            'password' => bcrypt('password'),
            'account_status' => User::STATUS_ACTIVE,
            'registration_type' => User::REGISTRATION_ADMIN,
        ]);

        $driver->roles()->attach($driverRole->id);

        Wallet::create([
            'user_id' => $driver->user_id,
            'balance' => $balance,
        ]);

        return $driver->fresh('wallet');
    }
}
