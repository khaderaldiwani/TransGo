<?php

namespace App\Services;

class PriceCalculatorService
{
    private const BASE_FARE = 10.00;
    private const PRICE_PER_KM = 2.50;
    private const PRICE_PER_MINUTE = 0.35;

    public function calculateSystemPrice(
        float $distanceKm,
        int $durationMinutes
    ): float {
        $price = self::BASE_FARE
            + ($distanceKm * self::PRICE_PER_KM)
            + ($durationMinutes * self::PRICE_PER_MINUTE);

        return round(max($price, self::BASE_FARE), 2);
    }
}
