<?php

namespace App\Providers;

use App\Events\BookingCreated;
use App\Models\Booking;
use App\Models\Notification;
use App\Models\Role;
use App\Models\Trip;
use App\Services\NotificationDispatchService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $identifier = (string) ($request->input('phone') ?: $request->input('email') ?: 'guest');

            return Limit::perMinute(5)->by($identifier.'|'.$request->ip());
        });

        RateLimiter::for('register', function (Request $request) {
            $identifier = (string) ($request->input('phone') ?: $request->input('email') ?: 'guest');

            return Limit::perMinute(5)->by($identifier.'|'.$request->ip());
        });

        RateLimiter::for('otp', function (Request $request) {
            $identifier = (string) ($request->input('phone') ?: $request->input('email') ?: 'guest');

            return Limit::perMinute(5)->by($identifier.'|'.$request->ip());
        });

        RateLimiter::for('api', function (Request $request) {
            $maxAttempts = app()->runningUnitTests() ? 1000 : 60;
            $identifier = (string) ($request->user()?->user_id ?: $request->ip());

            return Limit::perMinute($maxAttempts)->by($identifier);
        });

        Event::listen(BookingCreated::class, function (BookingCreated $event) {
            $booking = $event->booking;
            $driverUser = $booking->trip?->driver?->user;

            if (! $driverUser) {
                return;
            }

            $notification = Notification::create([
                'title' => 'طلب حجز جديد',
                'body' => "تم استلام طلب حجز جديد برمز {$booking->booking_code}.",
                'notification_type' => 'booking_requested',
                'reference_type' => Booking::class,
                'reference_id' => $booking->booking_id,
                'created_by' => $booking->passenger_id,
                'target_role' => Role::ROLE_DRIVER,
                'target_governorate_id' => $booking->pickupPoint?->governorate_id,
            ]);

            app(NotificationDispatchService::class)->sendExistingToUser(
                $notification,
                $driverUser->user_id,
                [
                    'booking_id' => $booking->booking_id,
                    'booking_code' => $booking->booking_code,
                    'trip_id' => $booking->trip_id,
                ]
            );
        });
    }
}
