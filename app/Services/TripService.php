<?php

namespace App\Services;

use App\Models\DriverProfile;
use App\Models\Trip;
use App\Models\TripStatus;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class TripService
{
    public function __construct(
        private readonly RouteService $routeService,
        private readonly PriceCalculatorService $priceCalculatorService,
        private readonly GovernorateResolverService $governorateResolverService,
        private readonly CommissionRateService $commissionRateService,
        private readonly TripClusterService $tripClusterService
    ) {
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

        $this->ensureSeatCapacity((int) $data['total_seats'], (int) $vehicle->seat_capacity);

        $route = $this->routeService->buildRoute($data['points']);
        $enrichedPoints = $this->governorateResolverService->enrichPointsWithAddresses($route['ordered_points']);
        $resolvedGovernorates = $this->governorateResolverService->resolveTripGovernorates($enrichedPoints);

        $this->ensureNoTimeOverlap(
            $driverProfile,
            (string) $data['departure_time'],
            (int) $route['estimated_duration_minutes']
        );

        $systemCalculatedPrice = $this->priceCalculatorService->calculateSystemPrice(
            (float) $route['estimated_distance_km'],
        );

        $this->validatePriceRange($data, $systemCalculatedPrice, (int) $vehicle->seat_capacity);

        $commissionSnapshot = $this->commissionRateService->ensureDriverCanCoverTripCommission(
            $actor,
            (bool) $data['allow_shared'],
            (bool) $data['allow_private'],
            (int) $data['total_seats'],
            ! empty($data['allow_shared']) ? (float) $data['shared_price'] : null,
            ! empty($data['allow_private']) ? (float) $data['private_price'] : null
        );

        $pendingStatus = TripStatus::query()
            ->where('status_key', TripStatus::PENDING)
            ->where('is_active', true)
            ->first();

        if (! $pendingStatus) {
            throw new RuntimeException('Pending trip status not found. Please seed trip statuses first.');
        }

        return DB::transaction(function () use ($data, $driverProfile, $route, $enrichedPoints, $resolvedGovernorates, $systemCalculatedPrice, $pendingStatus, $commissionSnapshot) {
            $trip = Trip::create([
                'driver_id' => $driverProfile->user_id,
                'start_governorate_id' => $resolvedGovernorates['start_governorate_id'],
                'end_governorate_id' => $resolvedGovernorates['end_governorate_id'],
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
                'commission_rate_id' => $commissionSnapshot['commission_rate_id'],
                'commission_percentage' => $commissionSnapshot['commission_percentage'],
                'max_commission_amount' => $commissionSnapshot['max_commission_amount'],
                'created_at' => now(),
            ]);

            $trip->points()->createMany($this->prepareTripPoints(
                $enrichedPoints,
                (string) $data['departure_time']
            ));

            $this->tripClusterService->assignTripToCluster($trip->fresh(['points', 'status']));

            return $trip->fresh(['points', 'status', 'startGovernorate', 'endGovernorate', 'cluster']);
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

    private function ensureNoTimeOverlap(
        DriverProfile $driverProfile,
        string $departureTime,
        int $estimatedDurationMinutes
    ): void {
        $newTripStart = Carbon::parse($departureTime);
        $newTripEnd = (clone $newTripStart)->addMinutes($estimatedDurationMinutes);

        $overlappingTrip = $driverProfile->trips()
            ->whereHas('status', function ($query) {
                $query->whereIn('status_key', [
                    TripStatus::PENDING,
                    TripStatus::ACTIVE,
                ]);
            })
            ->get()
            ->first(function (Trip $trip) use ($newTripStart, $newTripEnd) {
                $existingTripStart = Carbon::parse($trip->departure_time);
                $existingTripEnd = (clone $existingTripStart)
                    ->addMinutes((int) $trip->estimated_duration_minutes);

                return $newTripStart < $existingTripEnd
                    && $newTripEnd > $existingTripStart;
            });

        if ($overlappingTrip) {
            throw ValidationException::withMessages([
                'departure_time' => 'لا يمكن إنشاء رحلة تتداخل زمنياً مع رحلة أخرى للسائق قيد الانتظار أو نشطة.',
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

    private function validatePriceRange(array $data, float $systemCalculatedPrice, int $vehicleSeatCapacity): void
    {
        $minimumAllowedPrice = round($systemCalculatedPrice * 0.25, 2);
        $maximumAllowedPrice = round($systemCalculatedPrice, 2);

        if (! empty($data['allow_shared'])) {
            $this->assertPriceWithinRange(
                (float) $data['shared_price'],
                $minimumAllowedPrice,
                $maximumAllowedPrice,
                'shared_price',
                $vehicleSeatCapacity
            );
        }

        if (! empty($data['allow_private'])) {
            $this->assertPriceWithinRange(
                (float) $data['private_price'],
                $minimumAllowedPrice,
                $maximumAllowedPrice,
                'private_price',
                $vehicleSeatCapacity
            );
        }
    }

    private function assertPriceWithinRange(
        float $price,
        float $minimumAllowedPrice,
        float $maximumAllowedPrice,
        string $field,
        int $vehicleSeatCapacity
    ): void {
        if ($field === 'shared_price') {
            $minimumAllowedPrice = round($minimumAllowedPrice / $vehicleSeatCapacity, 2);
            $maximumAllowedPrice = round($maximumAllowedPrice / $vehicleSeatCapacity, 2);
        }

        if ($price < $minimumAllowedPrice || $price > $maximumAllowedPrice) {
            throw ValidationException::withMessages([
                $field => "السعر يجب أن يكون بين {$minimumAllowedPrice} و {$maximumAllowedPrice}.",
            ]);
        }
    }

    private function prepareTripPoints(array $orderedPoints, string $departureTime): array
    {
        return array_map(function (array $point) use ($departureTime) {
            return [
                'point_type' => $point['point_type'],
                'latitude' => $point['latitude'],
                'longitude' => $point['longitude'],
                'address' => $point['address'] ?? null,
                'note' => $point['note'] ?? null,
                'sequence_order' => $point['sequence_order'],
                'expected_arrival_time' => $this->routeService->resolveExpectedArrivalTime(
                    $departureTime,
                    isset($point['eta_offset_seconds']) ? (int) $point['eta_offset_seconds'] : null
                ),
            ];
        }, $orderedPoints);
    }
}
