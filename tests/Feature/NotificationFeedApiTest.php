<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationFeedApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_passenger_can_list_own_notifications_newest_first(): void
    {
        $passenger = $this->createUserWithRole(Role::ROLE_PASSENGER, 'Passenger Feed', 'passenger-feed@example.com');
        $otherPassenger = $this->createUserWithRole(Role::ROLE_PASSENGER, 'Other Passenger', 'other-passenger-feed@example.com');

        $oldNotification = $this->createNotification('Old passenger notification', Role::ROLE_PASSENGER, now()->subHour());
        $newNotification = $this->createNotification('New passenger notification', Role::ROLE_PASSENGER, now());
        $otherNotification = $this->createNotification('Other passenger notification', Role::ROLE_PASSENGER, now()->addMinute());

        UserNotification::create([
            'notification_id' => $oldNotification->notification_id,
            'user_id' => $passenger->user_id,
            'is_sent' => true,
            'sent_at' => now()->subHour(),
        ]);

        UserNotification::create([
            'notification_id' => $newNotification->notification_id,
            'user_id' => $passenger->user_id,
            'is_sent' => true,
            'sent_at' => now(),
        ]);

        UserNotification::create([
            'notification_id' => $otherNotification->notification_id,
            'user_id' => $otherPassenger->user_id,
            'is_sent' => true,
            'sent_at' => now(),
        ]);

        Sanctum::actingAs($passenger);

        $this->getJson('/api/v1/passenger/notifications')
            ->assertOk()
            ->assertJsonPath('data.items.0.title', 'New passenger notification')
            ->assertJsonPath('data.items.1.title', 'Old passenger notification')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonMissingPath('data.current_page')
            ->assertJsonMissingPath('data.items.0.reference')
            ->assertJsonMissingPath('data.items.0.target')
            ->assertJsonMissingPath('data.items.0.is_sent')
            ->assertJsonMissingPath('data.items.0.sent_at')
            ->assertJsonMissingPath('data.items.0.read_at')
            ->assertJsonMissingPath('data.items.0.received_at');

        $this->patchJson('/api/v1/passenger/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.updated_count', 2);

        $this->assertDatabaseHas('user_notifications', [
            'notification_id' => $oldNotification->notification_id,
            'user_id' => $passenger->user_id,
            'is_read' => true,
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'notification_id' => $newNotification->notification_id,
            'user_id' => $passenger->user_id,
            'is_read' => true,
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'notification_id' => $otherNotification->notification_id,
            'user_id' => $otherPassenger->user_id,
            'is_read' => false,
        ]);
    }

    public function test_driver_can_list_own_notifications_newest_first(): void
    {
        $driver = $this->createUserWithRole(Role::ROLE_DRIVER, 'Driver Feed', 'driver-feed@example.com');

        $oldNotification = $this->createNotification('Old driver notification', Role::ROLE_DRIVER, now()->subDay());
        $newNotification = $this->createNotification('New driver notification', Role::ROLE_DRIVER, now());

        UserNotification::create([
            'notification_id' => $oldNotification->notification_id,
            'user_id' => $driver->user_id,
            'is_read' => true,
            'is_sent' => true,
            'sent_at' => now()->subDay(),
            'read_at' => now()->subDay(),
        ]);

        UserNotification::create([
            'notification_id' => $newNotification->notification_id,
            'user_id' => $driver->user_id,
            'is_sent' => true,
            'sent_at' => now(),
        ]);

        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/driver/notifications')
            ->assertOk()
            ->assertJsonPath('data.items.0.title', 'New driver notification')
            ->assertJsonPath('data.items.0.is_read', false)
            ->assertJsonPath('data.items.1.title', 'Old driver notification')
            ->assertJsonPath('data.items.1.is_read', true)
            ->assertJsonCount(2, 'data.items');

        $this->patchJson('/api/v1/driver/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.updated_count', 1);

        $this->assertDatabaseHas('user_notifications', [
            'notification_id' => $newNotification->notification_id,
            'user_id' => $driver->user_id,
            'is_read' => true,
        ]);
    }

    private function createUserWithRole(string $roleName, string $fullName, string $email): User
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

    private function createNotification(string $title, string $targetRole, $createdAt): Notification
    {
        return Notification::create([
            'title' => $title,
            'body' => 'Notification body',
            'notification_type' => 'test_notification',
            'target_role' => $targetRole,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
