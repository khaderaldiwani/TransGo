<?php

use App\Services\DriverTripManagementService;
use App\Services\NotificationDispatchService;
use App\Models\Booking;
use App\Models\Notification;
use App\Models\Role;
use App\Models\Trip;
use App\Models\TripStatus;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('trips:auto-complete', function () {
    $result = app(DriverTripManagementService::class)->autoCompleteEligibleTrips();

    $this->info('Auto-completed trips: '.$result['count']);
    $this->line('Trip IDs: '.implode(', ', $result['trip_ids']));
})->purpose('Automatically complete active trips that exceeded the fallback completion window.');

Schedule::command('trips:auto-complete')->everyFiveMinutes();

Artisan::command('notifications:trip-reminders', function () {
    $notifications = app(NotificationDispatchService::class);
    $now = now();

    $trips = Trip::query()
        ->with([
            'status',
            'bookings.status',
            'bookings.passenger',
            'driver.user',
            'startGovernorate',
            'endGovernorate',
        ])
        ->whereNotNull('departure_time')
        ->where('departure_time', '>', $now)
        ->whereHas('status', fn ($query) => $query->whereIn('status_key', [TripStatus::PENDING, TripStatus::ACTIVE]))
        ->get();

    $sent = 0;

    foreach ($trips as $trip) {
        $departure = \Carbon\Carbon::parse($trip->departure_time);

        if ($departure->copy()->subHours(13)->lessThanOrEqualTo($now)) {
            $exists = Notification::query()
                ->where('notification_type', 'trip_departure_reminder_passenger')
                ->where('reference_type', 'trip')
                ->where('reference_id', $trip->trip_id)
                ->exists();

            if (! $exists) {
                $notification = Notification::create([
                    'title' => 'تذكير بموعد الرحلة',
                    'body' => "تبقى حوالي 13 ساعة على انطلاق الرحلة رقم {$trip->trip_id}.",
                    'notification_type' => 'trip_departure_reminder_passenger',
                    'reference_type' => 'trip',
                    'reference_id' => $trip->trip_id,
                    'target_role' => Role::ROLE_PASSENGER,
                    'target_governorate_id' => $trip->start_governorate_id,
                ]);

                $passengerIds = $trip->bookings
                    ->filter(fn (Booking $booking) => ! in_array($booking->status?->status_key, ['canceled', 'rejected'], true))
                    ->pluck('passenger_id')
                    ->filter()
                    ->unique()
                    ->values();

                $notifications->sendExistingToUsers($notification, $passengerIds, $notifications->tripData($trip));
                $sent++;
            }
        }

        if ($departure->copy()->subHours(12)->lessThanOrEqualTo($now)) {
            $exists = Notification::query()
                ->where('notification_type', 'trip_departure_reminder_driver')
                ->where('reference_type', 'trip')
                ->where('reference_id', $trip->trip_id)
                ->exists();

            if (! $exists && $trip->driver_id) {
                $notification = Notification::create([
                    'title' => 'تذكير بموعد الرحلة',
                    'body' => "تبقى حوالي 12 ساعة على انطلاق الرحلة رقم {$trip->trip_id}.",
                    'notification_type' => 'trip_departure_reminder_driver',
                    'reference_type' => 'trip',
                    'reference_id' => $trip->trip_id,
                    'target_role' => Role::ROLE_DRIVER,
                    'target_governorate_id' => $trip->start_governorate_id,
                ]);

                $notifications->sendExistingToUser($notification, $trip->driver_id, $notifications->tripData($trip));
                $sent++;
            }
        }
    }

    $this->info('Trip reminder notifications sent: '.$sent);
})->purpose('Send one-time passenger and driver reminders before trip departure.');

Schedule::command('notifications:trip-reminders')->everyFifteenMinutes();
