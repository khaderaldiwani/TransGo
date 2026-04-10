<?php

namespace App\Services;

class PriceCalculatorService
{
    private const BASE_FARE = 100.00;
    private const PRICE_PER_KM = 105.20;

    public function calculateSystemPrice(
        float $distanceKm,
        
    ): float {
        $price = self::BASE_FARE
            + ($distanceKm * self::PRICE_PER_KM);
            

        return round(max($price, self::BASE_FARE), 2);
    }
}
