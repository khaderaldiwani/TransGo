<?php

namespace Tests\Unit;

use App\Support\ApiDateTime;
use Carbon\Carbon;
use Tests\TestCase;

class ApiDateTimeTest extends TestCase
{
    public function test_it_serializes_datetime_in_application_timezone(): void
    {
        config(['app.timezone' => 'Europe/Istanbul']);

        $this->assertSame(
            '2026-07-22T12:00:00+03:00',
            ApiDateTime::toAppIso(Carbon::parse('2026-07-22 09:00:00', 'UTC'))
        );
    }

    public function test_it_keeps_local_wall_time_when_datetime_has_application_timezone(): void
    {
        config(['app.timezone' => 'Europe/Istanbul']);

        $this->assertSame(
            '2026-07-22T12:00:00+03:00',
            ApiDateTime::toAppIso(Carbon::parse('2026-07-22 12:00:00', 'Europe/Istanbul'))
        );
    }
}
