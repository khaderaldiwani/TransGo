<?php

namespace Tests\Feature;

use App\Mail\OtpCodeMail;
use App\Jobs\SendFcmTopicNotification;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationDispatchService;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AvailabilityNfrApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_reports_core_dependencies(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'System is healthy',
                'data' => [
                    'app' => 'ok',
                    'database' => 'ok',
                    'cache' => 'ok',
                    'queue' => 'ok',
                ],
            ]);
    }

    public function test_basic_endpoint_keeps_json_contract(): void
    {
        $response = $this->getJson('/api/v1/trip-statuses')
            ->assertOk();

        $this->assertSame(
            ['success', 'message', 'data', 'status_code', 'timestamp'],
            array_keys($response->json())
        );

        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'items',
            ],
            'status_code',
            'timestamp',
        ]);
    }

    public function test_cache_readiness_does_not_change_public_response(): void
    {
        $first = $this->getJson('/api/v1/governorates')
            ->assertOk()
            ->json();

        $second = $this->getJson('/api/v1/governorates')
            ->assertOk()
            ->json();

        unset($first['timestamp'], $second['timestamp']);

        $this->assertSame($first, $second);
    }

    public function test_normal_api_request_is_not_rate_limited(): void
    {
        $this->getJson('/api/v1/booking-statuses')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_otp_email_is_queued_for_availability(): void
    {
        Mail::fake();

        User::create([
            'full_name' => 'Availability OTP User',
            'phone' => '0992000001',
            'email' => 'availability-otp@example.com',
            'password' => Hash::make('password123'),
            'account_status' => User::STATUS_ACTIVE,
        ]);

        app(OtpService::class)->sendByEmail('availability-otp@example.com');

        Mail::assertQueued(OtpCodeMail::class);
    }

    public function test_notification_fcm_can_be_dispatched_to_queue_when_enabled(): void
    {
        config(['services.firebase.queue' => true]);
        Queue::fake();

        $notification = Notification::create([
            'title' => 'Availability notification',
            'body' => 'Queued FCM readiness check.',
            'notification_type' => 'availability_test',
            'target_role' => 'passenger',
        ]);

        $result = app(NotificationDispatchService::class)
            ->sendToTopic($notification, 'availability_test_topic');

        $this->assertSame([
            'sent' => false,
            'queued' => true,
            'topic' => 'availability_test_topic',
        ], $result);

        Queue::assertPushed(SendFcmTopicNotification::class);
    }
}
