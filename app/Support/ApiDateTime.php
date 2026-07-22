<?php

namespace App\Support;

use Carbon\CarbonInterface;

class ApiDateTime
{
    public static function toAppIso(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->copy()->timezone(config('app.timezone'))->toIso8601String();
        }

        return \Carbon\Carbon::parse($value)
            ->timezone(config('app.timezone'))
            ->toIso8601String();
    }
}
