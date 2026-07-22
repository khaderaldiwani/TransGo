<?php

namespace App\Services;

use App\Models\VehicleCategory;

class PriceCalculatorService
{
    private const BASE_FARE = 100.00;

    public function calculateSystemPrice(
        float $distanceKm,
        ?float $pricePerKm = null
    ): float {
        $pricePerKm ??= VehicleCategory::DEFAULT_PRICE_PER_KM;

        $price = self::BASE_FARE
            + ($distanceKm * $pricePerKm);

        return round(max($price, self::BASE_FARE), 2);
    }
}
