<?php

namespace App\Services;

use App\Models\DriverProfile;
use App\Models\Trip;
use App\Models\TripStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class TripService
{
    public function __construct(
        private readonly RouteService $routeService,
        private readonly PriceCalculatorService $priceCalculatorService
    ) 
    {
    }

    public function createTrip(array $data, User $actor): Trip
    {
        $driverProfile = $this->resolveDriverProfile($actor);
        $vehicle = $driverProfile->vehicles()->first();

        if (! $vehicle) {
            throw ValidationException::withMessages([
                'driver_id' => 'لا يمكن إنشاء رحلة بدون مركبة مسجلة للسائق.',
            ]);
        }

        $this->ensureNoActiveTrip($driverProfile);
        $this->ensureSeatCapacity((int) $data['total_seats'], (int) $vehicle->seat_capacity);

        $route = $this->routeService->buildRoute($data['points']);

        $systemCalculatedPrice = $this->priceCalculatorService->calculateSystemPrice(
            (float) $route['estimated_distance_km'],
            (int) $route['estimated_duration_minutes'],
        );

        $this->validatePriceRange($data, $systemCalculatedPrice);

        $pendingStatus = TripStatus::query()
            ->where('status_key', TripStatus::PENDING)
            ->where('is_active', true)
            ->first();

        if (! $pendingStatus) {
            throw new RuntimeException('Pending trip status not found. Please seed trip statuses first.');
        }

        return DB::transaction(function () use ($data, $driverProfile, $route, $systemCalculatedPrice, $pendingStatus) {
            $trip = Trip::create([
                'driver_id' => $driverProfile->user_id,
                'start_governorate_id' => $data['start_governorate_id'],
                'end_governorate_id' => $data['end_governorate_id'],
                'departure_time' => $data['departure_time'],
                'estimated_duration_minutes' => $route['estimated_duration_minutes'],
                'estimated_distance_km' => $route['estimated_distance_km'],
                'total_seats' => $data['total_seats'],
                'available_seats' => $data['total_seats'],
                'allow_shared' => $data['allow_shared'],
                'allow_private' => $data['allow_private'],
                'is_private_booked' => false,
                'shared_price' => $data['allow_shared'] ? $data['shared_price'] : null,
                'private_price' => $data['allow_private'] ? $data['private_price'] : null,
                'system_calculated_price' => $systemCalculatedPrice,
                'route_polyline' => $route['polyline'],
                'status_id' => $pendingStatus->status_id,
                'created_at' => now(),
            ]);

            $trip->points()->createMany($this->prepareTripPoints($route['ordered_points']));

            return $trip->load(['points', 'status', 'startGovernorate', 'endGovernorate']);
        });
    }

    private function resolveDriverProfile(User $actor): DriverProfile
    {
        $actor->loadMissing('driverProfile.vehicles');

        if (! $actor->driverProfile) {
            throw ValidationException::withMessages([
                'driver_id' => 'المستخدم الحالي لا يملك ملف سائق.',
            ]);
        }

        return $actor->driverProfile;
    }

    private function ensureNoActiveTrip(DriverProfile $driverProfile): void
    {
        $hasActiveTrip = $driverProfile->trips()
            ->whereHas('status', function ($query) {
                $query->whereIn('status_key', [
                    TripStatus::PENDING,
                    TripStatus::ACTIVE,
                ]);
            })
            ->exists();

        if ($hasActiveTrip) {
            throw ValidationException::withMessages([
                'driver_id' => 'لا يمكن للسائق إنشاء أكثر من رحلة نشطة أو قيد الانتظار في نفس الوقت.',
            ]);
        }
    }

    private function ensureSeatCapacity(int $requestedSeats, int $vehicleSeatCapacity): void
    {
        if ($requestedSeats > $vehicleSeatCapacity) {
            throw ValidationException::withMessages([
                'total_seats' => 'عدد المقاعد المطلوبة يتجاوز سعة السيارة المسجلة في النظام.',
            ]);
        }
    }

    private function validatePriceRange(array $data, float $systemCalculatedPrice): void
    {
        $minimumAllowedPrice = round($systemCalculatedPrice * 0.5, 2);
        $maximumAllowedPrice = round($systemCalculatedPrice, 2);

        if (! empty($data['allow_shared'])) {
            $this->assertPriceWithinRange(
                (float) $data['shared_price'],
                $minimumAllowedPrice,
                $maximumAllowedPrice,
                'shared_price'
            );
        }

        if (! empty($data['allow_private'])) {
            $this->assertPriceWithinRange(
                (float) $data['private_price'],
                $minimumAllowedPrice,
                $maximumAllowedPrice,
                'private_price'
            );
        }
    }

    private function assertPriceWithinRange(
        float $price,
        float $minimumAllowedPrice,
        float $maximumAllowedPrice,
        string $field
    ): void {
        if ($price < $minimumAllowedPrice || $price > $maximumAllowedPrice) {
            throw ValidationException::withMessages([
                $field => "السعر يجب أن يكون بين {$minimumAllowedPrice} و {$maximumAllowedPrice}.",
            ]);
        }
    }

    private function prepareTripPoints(array $orderedPoints): array
    {
        return array_map(function (array $point) {
            return [
                'point_type' => $point['point_type'],
                'latitude' => $point['latitude'],
                'longitude' => $point['longitude'],
                'address' => $point['address'] ?? null,
                'sequence_order' => $point['sequence_order'],
            ];
        }, $orderedPoints);
    }
}
