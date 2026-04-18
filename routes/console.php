<?php

use App\Services\DriverTripManagementService;
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
