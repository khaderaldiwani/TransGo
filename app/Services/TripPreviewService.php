<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Validation\ValidationException;

class TripPreviewService
{
    public function __construct(
        private readonly RouteService $routeService,
        private readonly PriceCalculatorService $priceCalculatorService,
        private readonly GovernorateResolverService $governorateResolverService,
        private readonly CommissionRateService $commissionRateService
    ) {
    }

    public function preview(array $data, User $actor): array
    {
        $actor->loadMissing('driverProfile.vehicles.category');

        $vehicle = data_get($actor, 'driverProfile.vehicles.0');

        if (! $vehicle) {
            throw ValidationException::withMessages([
                'driver_id' => 'لا يمكن معاينة الرحلة بدون مركبة مسجلة للسائق.',
            ]);
        }

        $route = $this->routeService->buildRoute($data['points']);
        $enrichedPoints = $this->governorateResolverService->enrichPointsWithAddresses($route['ordered_points']);
        $resolvedGovernorates = $this->governorateResolverService->resolveTripGovernorates($enrichedPoints);
        $systemCalculatedPrice = $this->priceCalculatorService->calculateSystemPrice(
            (float) $route['estimated_distance_km'],
            $vehicle->category?->price_per_km !== null ? (float) $vehicle->category->price_per_km : null
        );
        $commissionSnapshot = $this->commissionRateService->previewCommissionSnapshot(
            $actor,
            (bool) ($data['allow_shared'] ?? false),
            (bool) ($data['allow_private'] ?? false),
            (int) $data['total_seats'],
            $systemCalculatedPrice,
            (int) $vehicle->seat_capacity
        );

        return [
            'estimated_distance_km' => $route['estimated_distance_km'],
            'estimated_duration_minutes' => $route['estimated_duration_minutes'],
            'route_polyline' => $route['polyline'],
            'system_calculated_price' => $systemCalculatedPrice,
            'commission' => [
                'percentage' => $commissionSnapshot['commission_percentage'],
                'max_potential_revenue' => $commissionSnapshot['max_potential_revenue'],
                'max_commission_amount' => $commissionSnapshot['max_commission_amount'],
                'wallet_balance' => $commissionSnapshot['wallet_balance'],
            ],
            'shared_price_range' => ! empty($data['allow_shared'])
                ? $this->buildSharedPriceRange($systemCalculatedPrice, (int) $vehicle->seat_capacity)
                : null,
            'private_price_range' => ! empty($data['allow_private'])
                ? $this->buildPrivatePriceRange($systemCalculatedPrice)
                : null,
            'start_governorate' => $resolvedGovernorates['start_governorate'],
            'end_governorate' => $resolvedGovernorates['end_governorate'],
            'ordered_points' => collect($enrichedPoints)->map(function (array $point) use ($data, $route) {
                $expectedArrivalTime = isset($data['departure_time'])
                    ? $this->routeService->resolveExpectedArrivalTime(
                        (string) $data['departure_time'],
                        isset($point['eta_offset_seconds']) ? (int) $point['eta_offset_seconds'] : null
                    )
                    : null;

                return [
                    ...$point,
                    'expected_arrival_time' => \App\Support\ApiDateTime::toAppIso($expectedArrivalTime),
                ];
            })->values()->all(),
        ];
    }

    private function buildSharedPriceRange(float $systemCalculatedPrice, int $vehicleSeatCapacity): array
    {
        return [
            'min' => round(($systemCalculatedPrice * 0.25) / $vehicleSeatCapacity, 2),
            'max' => round($systemCalculatedPrice / $vehicleSeatCapacity, 2),
        ];
    }

    private function buildPrivatePriceRange(float $systemCalculatedPrice): array
    {
        return [
            'min' => round($systemCalculatedPrice * 0.25, 2),
            'max' => round($systemCalculatedPrice, 2),
        ];
    }
}
