<?php

namespace App\Services;

use App\Models\DriverReview;
use App\Models\Role;
use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripStatus;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\Wallet;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class DriverManagementService
{
    public function __construct(protected AuditLogService $auditLogService)
    {
    }

    public function listDrivers(array $filters): LengthAwarePaginator
    {
        $query = User::whereHas('roles', fn ($q) => $q->where('name', Role::ROLE_DRIVER))
            ->with(['roles', 'driverProfile', 'wallet']);

        if (! empty($filters['name'])) {
            $query->where('full_name', 'like', "%{$filters['name']}%");
        }

        if (! empty($filters['phone'])) {
            $query->where('phone', 'like', "%{$filters['phone']}%");
        }

        if (! empty($filters['email'])) {
            $query->where('email', 'like', "%{$filters['email']}%");
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];

            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");

                if (is_numeric($search)) {
                    $q->orWhere('user_id', (int) $search);
                }
            });
        }

        if (isset($filters['account_status']) && $filters['account_status'] !== '') {
            $query->where('account_status', $filters['account_status']);
        }

        if (! empty($filters['approval_status'])) {
            $query->whereHas('driverProfile', fn ($q) => $q->where('approval_status', $filters['approval_status']));
        }

        $sortBy = in_array($filters['sort_by'] ?? '', ['full_name', 'email', 'created_at', 'account_status'], true)
            ? $filters['sort_by']
            : 'created_at';
        $sortOrder = ($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sortBy, $sortOrder)
            ->paginate($filters['per_page'] ?? 15);
    }

    public function getDriver(int $id): array
    {
        $user = User::whereHas('roles', fn ($q) => $q->where('name', Role::ROLE_DRIVER))
            ->with(['roles', 'driverProfile.vehicles.category', 'driverProfile.vehicles.images', 'wallet'])
            ->find($id);

        if (! $user) {
            throw new RuntimeException('السائق غير موجود.', 404);
        }

        $trips = Trip::query()
            ->with([
                'status',
                'startGovernorate',
                'endGovernorate',
                'points',
                'bookings.passenger',
            ])
            ->where('driver_id', $user->user_id)
            ->orderByDesc('created_at')
            ->get();

        $reviews = DriverReview::query()
            ->with(['passenger', 'booking.trip'])
            ->where('driver_id', $user->user_id)
            ->orderByDesc('created_at')
            ->get();

        $vehicle = $user->driverProfile?->vehicles?->first();
        $tripStatusCounts = $this->buildTripStatusCounts($trips);
        $tripRows = $trips->map(fn (Trip $trip) => $this->transformTripHistoryRow($trip))->values();
        $earningsRows = $trips->map(fn (Trip $trip) => $this->transformEarningRow($trip))->values();
        $ratingsSummary = $this->buildRatingsSummary($reviews);
        $carPhotoUrls = $vehicle?->images?->map(
            fn ($image) => $this->absoluteFileUrl($image->image_url)
        )->filter()->values()->all() ?? [];

        $accountStatus = $this->resolveDriverAccountStatus(
            (int) $user->account_status,
            $user->driverProfile?->approval_status
        );
        $isCarUnderDriverName = ! empty($vehicle?->ownership_document);
        $certifiedAgencyDocument = $this->absoluteFileUrl($vehicle?->certified_agency);
        $saleContractImage = $this->absoluteFileUrl($vehicle?->mechanical_car);
        $saleContractValidationFlag = $saleContractImage !== null
            ? $this->validateSaleContract($vehicle?->ownership_document)
            : null;

        return [
            'personal_information' => [
                'full_name' => $user->full_name,
                'phone' => $user->phone,
                'address' => $user->driverProfile?->address,
                'personal_photo' => $this->absoluteFileUrl($user->driverProfile?->personal_photo),
                'account_status' => $accountStatus,
                'id_card_image' => $this->absoluteFileUrl($user->driverProfile?->id_card),
                'license_image' => $this->absoluteFileUrl($user->driverProfile?->license_image),
                'email' => $user->email,
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'vehicle_information' => [
                'car_type' => $vehicle?->car_type,
                'vehicle_category' => $vehicle?->categoryPayload(),
                'plate_number' => $user->driverProfile?->id_card,
                'car_photos' => $carPhotoUrls,
                'driving_license_image' => $this->absoluteFileUrl($user->driverProfile?->license_image),
                'insurance_image' => $this->absoluteFileUrl($vehicle?->insurance_image),
                'certified_agency_document' => $certifiedAgencyDocument,
                'sale_contract' => [
                    'exists' => $saleContractImage !== null,
                    'contract_image' => $saleContractImage,
                    'contract_validation_flag' => $saleContractValidationFlag,
                ],
                'validation' => [
                    'is_car_under_driver_name' => $isCarUnderDriverName,
                    'certified_agency_document_required' => ! $isCarUnderDriverName,
                    'certified_agency_document_valid' => $isCarUnderDriverName || $certifiedAgencyDocument !== null,
                ],
            ],
            'trips_history' => [
                'filters' => [
                    'supported_statuses' => ['pending', 'active', 'completed', 'cancelled'],
                ],
                'trips' => $tripRows,
                ...$tripStatusCounts,
            ],
            'financial_earnings' => [
                'trips' => $earningsRows,
                'total_gross_earnings' => (float) $earningsRows->sum('trip_price'),
                'total_commission' => (float) $earningsRows->sum('commission_amount'),
                'total_net_earnings' => (float) $earningsRows->sum('net_amount'),
            ],
            'ratings_reviews' => $ratingsSummary,
        ];
    }

    public function getAuthenticatedDriverProfile(User $user, int $perPage = 10): array
    {
        $driver = $this->resolveDriver($user->user_id);

        $vehicle = $driver->driverProfile?->vehicles?->first();
        $carPhotoUrls = $vehicle?->images?->map(
            fn ($image) => $this->absoluteFileUrl($image->image_url)
        )->filter()->values()->all() ?? [];

        $reviews = DriverReview::query()
            ->with(['passenger'])
            ->where('driver_id', $driver->user_id)
            ->where('rated_user_type', Role::ROLE_DRIVER)
            ->where('is_visible', true)
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return [
            'profile' => [
                'name' => $driver->full_name,
                'photo' => $this->absoluteFileUrl($driver->driverProfile?->personal_photo),
                'phone_number' => $driver->phone,
                'email' => $driver->email,
                'car_plate_number' => $driver->driverProfile?->id_card,
                'car_type' => $vehicle?->car_type,
                'vehicle_category' => $vehicle?->categoryPayload(),
                'car_photos' => $carPhotoUrls,
                'overall_rating' => $this->calculateDriverRating($driver->user_id),
            ],
            'reviews' => $reviews,
        ];
    }

    public function getDriverProfileForPassenger(int $driverId, int $perPage = 10): array
    {
        $driver = $this->resolveDriver($driverId);

        $vehicle = $driver->driverProfile?->vehicles?->first();
        $carPhotoUrls = $vehicle?->images?->map(
            fn ($image) => $this->absoluteFileUrl($image->image_url)
        )->filter()->values()->all() ?? [];

        $reviews = DriverReview::query()
            ->with(['passenger'])
            ->where('driver_id', $driver->user_id)
            ->where('rated_user_type', Role::ROLE_DRIVER)
            ->where('is_visible', true)
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return [
            'profile' => [
                'name' => $driver->full_name,
                'photo' => $this->absoluteFileUrl($driver->driverProfile?->personal_photo),
                'phone_number' => $driver->phone,
                'car_plate_number' => $driver->driverProfile?->id_card,
                'car_type' => $vehicle?->car_type,
                'vehicle_category' => $vehicle?->categoryPayload(),
                'car_photos' => $carPhotoUrls,
                'overall_rating' => $this->calculateDriverRating($driver->user_id),
            ],
            'reviews' => $reviews,
        ];
    }

    public function getPassengerProfile(int $id): array
    {
        $user = User::whereHas('roles', fn ($q) => $q->where('name', Role::ROLE_PASSENGER))
            ->find($id);

        if (! $user) {
            throw new RuntimeException('Passenger not found.', 404);
        }

        return [
            'photo' => $this->absoluteFileUrl($user->profile_photo),
            'name' => $user->full_name,
            'phone_number' => $user->phone,
            'cancelled_reservations_count' => $this->buildPassengerReservationCount($user, 'cancelled'),
            'completed_reservations_count' => $this->buildPassengerReservationCount($user, 'completed'),
            'rating' => $this->buildPassengerRating($user),
        ];
    }

    private function calculateDriverRating(int $driverId): float
    {
        $rating = DriverReview::query()
            ->where('driver_id', $driverId)
            ->where('rated_user_type', Role::ROLE_DRIVER)
            ->where('is_visible', true)
            ->avg('rating');

        return $rating !== null ? round((float) $rating, 2) : 0.0;
    }

    private function buildPassengerReservationCount(User $user, string $type): int
    {
        $query = Booking::query()->where('passenger_id', $user->user_id);

        if ($type === 'completed') {
            $query->where(function ($query) {
                $query->whereHas('status', fn ($q) => $q->where('status_key', 'completed'))
                    ->orWhereHas('trip.status', fn ($q) => $q->whereIn('status_key', [TripStatus::COMPLETED, TripStatus::AUTO_COMPLETED]));
            });
        } else {
            $query->where(function ($query) {
                $query->whereHas('status', fn ($q) => $q->whereIn('status_key', ['canceled', 'rejected']))
                    ->orWhereHas('trip.status', fn ($q) => $q->where('status_key', TripStatus::CANCELED));
            });
        }

        return $query->count();
    }

    private function buildPassengerRating(User $user): float
    {
        $rating = DriverReview::query()
            ->where('passenger_id', $user->user_id)
            ->where('rated_user_type', Role::ROLE_PASSENGER)
            ->where('is_visible', true)
            ->avg('rating');

        return $rating !== null ? round((float) $rating, 2) : 0.0;
    }

    public function createDriver(array $data, User $actor): array
    {
        if (! $actor->hasAnyRole([Role::ROLE_ADMIN, Role::ROLE_EMPLOYEE])) {
            throw new RuntimeException('Forbidden.', 403);
        }

        return DB::transaction(function () use ($data, $actor) {
            $temporaryPassword = Str::random(10);
            $licenseImage = $this->storeFile($data['license_image'], 'drivers/licenses');
            $personalPhoto = $this->storeFile($data['personal_photo'], 'drivers/personal-photos');
            $mechanicalCarImage = $this->storeFile($data['mechanical_car'], 'vehicles/mechanical');
            $insuranceImage = $this->storeNullableFile($data['insurance_image'] ?? null, 'vehicles/insurance');
            $ownershipDocument = $this->storeNullableFile($data['ownership_document'] ?? null, 'vehicles/ownership-documents');
            $certifiedAgency = $this->storeNullableFile($data['certified_agency'] ?? null, 'vehicles/certified-agency');

            $driver = User::create([
                'full_name' => $data['full_name'],
                'phone' => $data['phone'],
                'email' => $data['email'],
                'password' => Hash::make($temporaryPassword),
                'must_change_password' => 1,
                'account_status' => User::STATUS_ACTIVE,
                'created_by' => $actor->user_id,
                'registration_type' => User::REGISTRATION_ADMIN,
            ]);

            $driverRole = Role::where('name', Role::ROLE_DRIVER)->first();

            if (! $driverRole) {
                throw new RuntimeException('Driver role not found. Please seed roles first.', 500);
            }

            $driver->roles()->attach($driverRole->id);

            $driverProfile = DriverProfile::create([
                'user_id' => $driver->user_id,
                'address' => $data['address'],
                'id_card' => $data['id_card'],
                'license_image' => $this->toPublicStoragePath($licenseImage),
                'personal_photo' => $this->toPublicStoragePath($personalPhoto),
                'approval_status' => DriverProfile::APPROVAL_APPROVED,
            ]);

            $vehicle = Vehicle::create([
                'driver_id' => $driverProfile->user_id,
                'vehicle_category_id' => $data['vehicle_category_id'] ?? $this->defaultVehicleCategoryId(),
                'car_type' => $data['car_type'],
                'seat_capacity' => $data['seat_capacity'],
                'mechanical_car' => $this->toPublicStoragePath($mechanicalCarImage),
                'insurance_image' => $this->toPublicStoragePath($insuranceImage),
                'ownership_document' => $this->toPublicStoragePath($ownershipDocument),
                'certified_agency' => $this->toPublicStoragePath($certifiedAgency),
            ]);

            foreach ($data['vehicle_images'] as $imageFile) {
                $vehicle->images()->create([
                    'image_url' => $this->toPublicStoragePath($this->storeFile($imageFile, 'vehicles/gallery')),
                ]);
            }

            Wallet::firstOrCreate(
                ['user_id' => $driver->user_id],
                ['balance' => 0]
            );

            $this->auditLogService->log(
                $actor,
                'driver.created',
                User::class,
                $driver->user_id,
                null,
                [
                    'full_name' => $driver->full_name,
                    'phone' => $driver->phone,
                    'email' => $driver->email,
                ],
                "Driver {$driver->full_name} (ID: {$driver->user_id}) created by {$actor->full_name} (ID: {$actor->user_id})."
            );

            $vehicleData = $vehicle->load('images')->toArray();
            $vehicleData['vehicle_category'] = $vehicle->loadMissing('category')->categoryPayload();

            return [
                'driver' => $driver->load(['roles', 'wallet']),
                'driver_profile' => $driverProfile,
                'vehicle' => $vehicleData,
                'temporary_password' => $temporaryPassword,
            ];
        });
    }

    private function storeFile(UploadedFile $file, string $directory): string
    {
        return $file->store($directory, 'public');
    }

    private function storeNullableFile(?UploadedFile $file, string $directory): ?string
    {
        if (! $file) {
            return null;
        }

        return $this->storeFile($file, $directory);
    }

    private function toPublicStoragePath(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return 'storage/'.$path;
    }

    public function toggleStatus(int $id, User $actor): User
    {
        $user = $this->resolveDriver($id);

        $oldStatus = $user->account_status;
        $newStatus = $oldStatus === User::STATUS_ACTIVE ? User::STATUS_INACTIVE : User::STATUS_ACTIVE;

        $user->update(['account_status' => $newStatus]);

        $this->auditLogService->log(
            $actor,
            'driver.status_toggled',
            User::class,
            $user->user_id,
            ['account_status' => $oldStatus],
            ['account_status' => $newStatus],
            "Driver {$user->full_name} (ID: {$user->user_id}) status changed from {$oldStatus} to {$newStatus} by {$actor->full_name} (ID: {$actor->user_id})."
        );

        return $user->fresh(['roles', 'driverProfile.vehicles.category', 'driverProfile.vehicles.images', 'wallet']);
    }


    private function resolveDriver(int $id): User
    {
        $user = User::whereHas('roles', fn ($q) => $q->where('name', Role::ROLE_DRIVER))
            ->with(['roles', 'driverProfile.vehicles.category', 'driverProfile.vehicles.images', 'wallet'])
            ->find($id);

        if (! $user) {
            throw new RuntimeException('Driver not found.', 404);
        }

        return $user;
    }
    private function transformTripHistoryRow(Trip $trip): array
    {
        $startPoint = $trip->points->firstWhere('point_type', 'start') ?? $trip->points->sortBy('sequence_order')->first();
        $endPoint = $trip->points->firstWhere('point_type', 'end') ?? $trip->points->sortByDesc('sequence_order')->first();
        $firstPassenger = $trip->bookings->first()?->passenger;

        return [
            'id' => $trip->trip_id,
            'from_location' => [
                'address' => $startPoint?->address ?? $trip->startGovernorate?->name,
                'coordinates' => [
                    'lat' => $startPoint?->latitude !== null ? (float) $startPoint->latitude : null,
                    'lng' => $startPoint?->longitude !== null ? (float) $startPoint->longitude : null,
                ],
            ],
            'to_location' => [
                'address' => $endPoint?->address ?? $trip->endGovernorate?->name,
                'coordinates' => [
                    'lat' => $endPoint?->latitude !== null ? (float) $endPoint->latitude : null,
                    'lng' => $endPoint?->longitude !== null ? (float) $endPoint->longitude : null,
                ],
            ],
            'datetime' => $trip->departure_time?->toIso8601String(),
            'number_of_seats' => (int) $trip->total_seats,
            'trip_price' => $this->resolveTripPrice($trip),
            'status' => $this->normalizeTripStatus($trip->status?->status_key),
            'passenger_name' => $firstPassenger?->full_name,
            'passenger_phone' => $firstPassenger?->phone,
            'passenger_image' => $firstPassenger?->profile_photo,
            'passenger_rating' => $firstPassenger?->rating !== null ? (float) $firstPassenger->rating : null,
            'created_at' => $trip->created_at?->toIso8601String(),
        ];
    }

    private function transformEarningRow(Trip $trip): array
    {
        $tripPrice = $this->resolveTripPrice($trip);
        $commissionAmount = $trip->commission_amount !== null ? (float) $trip->commission_amount : 0.0;
        $netAmount = $trip->net_revenue_amount !== null
            ? (float) $trip->net_revenue_amount
            : (float) max(0, $tripPrice - $commissionAmount);

        return [
            'trip_id' => $trip->trip_id,
            'trip_price' => $tripPrice,
            'commission_amount' => $commissionAmount,
            'net_amount' => $netAmount,
        ];
    }

    private function resolveTripPrice(Trip $trip): float
    {
        if ($trip->gross_revenue_amount !== null) {
            return (float) $trip->gross_revenue_amount;
        }

        if ($trip->system_calculated_price !== null) {
            return (float) $trip->system_calculated_price;
        }

        if ($trip->shared_price !== null) {
            return (float) $trip->shared_price;
        }

        if ($trip->private_price !== null) {
            return (float) $trip->private_price;
        }

        return 0.0;
    }

    private function buildTripStatusCounts(Collection $trips): array
    {
        $statusKeys = $trips->map(
            fn (Trip $trip) => $this->normalizeTripStatus($trip->status?->status_key)
        );

        return [
            'total_trips_count' => $trips->count(),
            'completed_trips_count' => $statusKeys->filter(fn ($status) => $status === 'completed')->count(),
            'cancelled_trips_count' => $statusKeys->filter(fn ($status) => $status === 'cancelled')->count(),
            'pending_trips_count' => $statusKeys->filter(fn ($status) => $status === 'pending')->count(),
            'active_trips_count' => $statusKeys->filter(fn ($status) => $status === 'active')->count(),
        ];
    }

    private function normalizeTripStatus(?string $statusKey): string
    {
        return match ($statusKey) {
            TripStatus::ACTIVE => 'active',
            TripStatus::COMPLETED, TripStatus::AUTO_COMPLETED => 'completed',
            TripStatus::CANCELED => 'cancelled',
            default => 'pending',
        };
    }

    private function buildRatingsSummary(Collection $reviews): array
    {
        $totalRatings = $reviews->count();
        $sumStars = $reviews->sum('rating');

        $starsBreakdown = [
            '1' => $reviews->where('rating', 1)->count(),
            '2' => $reviews->where('rating', 2)->count(),
            '3' => $reviews->where('rating', 3)->count(),
            '4' => $reviews->where('rating', 4)->count(),
            '5' => $reviews->where('rating', 5)->count(),
        ];

        return [
            'average_rating' => $totalRatings > 0 ? round($sumStars / $totalRatings, 2) : 0.0,
            'total_ratings_count' => $totalRatings,
            'stars_breakdown' => $starsBreakdown,
            'reviews' => $reviews->map(function (DriverReview $review) {
                return [
                    'stars' => (int) $review->rating,
                    'comment' => $review->comment,
                    'passenger_name' => $review->passenger?->full_name,
                    'passenger_photo' => $review->passenger?->profile_photo,
                    'passenger_rating' => $review->passenger?->rating !== null ? (float) $review->passenger->rating : null,
                    'trip_id' => $review->booking?->trip_id,
                    'created_at' => $review->created_at?->toIso8601String(),
                ];
            })->values(),
        ];
    }

    private function absoluteFileUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        return url('/'.ltrim($path, '/'));
    }

    private function validateSaleContract(?string $documentField): bool
    {
        if (! $documentField) {
            return false;
        }

        $normalized = Str::lower($documentField);

        return Str::contains($normalized, ['signed', 'stamp', 'owner', 'driver']);
    }

    private function resolveDriverAccountStatus(int $rawStatus, ?string $approvalStatus): string
    {
        if ($approvalStatus === DriverProfile::APPROVAL_PENDING) {
            return 'pending';
        }

        if ($rawStatus === User::STATUS_ACTIVE) {
            return 'active';
        }

        return 'suspended';
    }

    private function defaultVehicleCategoryId(): ?int
    {
        return VehicleCategory::query()
            ->where('name', VehicleCategory::DEFAULT_NAME)
            ->value('category_id');
    }

}
