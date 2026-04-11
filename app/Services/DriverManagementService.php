<?php

namespace App\Services;
use App\Models\AuditLog;
use App\Models\DriverProfile;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class DriverManagementService
{
    public function listDrivers(array $filters): LengthAwarePaginator
    {
        $query = User::whereHas('roles', fn($q) => $q->where('name', Role::ROLE_DRIVER))
            ->with(['roles', 'driverProfile']);

        // Advanced filters
        if (!empty($filters['name'])) {
            $query->where('full_name', 'like', "%{$filters['name']}%");
        }

        if (!empty($filters['phone'])) {
            $query->where('phone', 'like', "%{$filters['phone']}%");
        }

        if (!empty($filters['email'])) {
            $query->where('email', 'like', "%{$filters['email']}%");
        }

        // Legacy search filter (searches across multiple fields)
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if (isset($filters['account_status']) && $filters['account_status'] !== '') {
            $query->where('account_status', $filters['account_status']);
        }

        if (!empty($filters['approval_status'])) {
            $query->whereHas('driverProfile', fn($q) => $q->where('approval_status', $filters['approval_status']));
        }

        $sortBy    = in_array($filters['sort_by'] ?? '', ['full_name', 'email', 'created_at', 'account_status'])
            ? $filters['sort_by']
            : 'created_at';
        $sortOrder = ($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sortBy, $sortOrder)
                     ->paginate($filters['per_page'] ?? 15);
    }

    public function getDriver(int $id): User
    {
        $user = User::whereHas('roles', fn($q) => $q->where('name', Role::ROLE_DRIVER))
            ->with(['roles', 'driverProfile.vehicles.images'])
            ->find($id);

        if (!$user) {
            throw new RuntimeException('السائق غير موجود.', 404);
        }

        return $user;
    }

    public function createDriver(array $data, User $actor): array
    {
        if (!$actor->hasAnyRole([Role::ROLE_ADMIN, Role::ROLE_EMPLOYEE])) {
            throw new RuntimeException('Forbidden.', 403);
        }

        return DB::transaction(function () use ($data, $actor) {
            $temporaryPassword = Str::random(10);
        //    $idCardImage = $this->storeFile($data['id_card_image'], 'drivers/id-cards');
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
                'must_change_password'=>1,
                'account_status' => User::STATUS_ACTIVE,
                'created_by' => $actor->user_id,
                'registration_type' => User::REGISTRATION_ADMIN,
            ]);

            $driverRole = Role::where('name', Role::ROLE_DRIVER)->first();

            if (!$driverRole) {
                throw new RuntimeException('Driver role not found. Please seed roles first.', 500);
            }

            $driver->roles()->attach($driverRole->id);

            $driverProfile = DriverProfile::create([
                'user_id' => $driver->user_id,
                'address' => $data['address'],
                'id_card' =>$data['id_card'],
                'license_image' => $this->toPublicStoragePath($licenseImage),
                'personal_photo' => $this->toPublicStoragePath($personalPhoto),
                'approval_status' => DriverProfile::APPROVAL_APPROVED,
            ]);

            $vehicle = Vehicle::create([
                'driver_id' => $driverProfile->user_id,
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

            AuditLog::create([
                'actor_user_id' => $actor->user_id,
                'action' => 'driver.created',
                'entity_type' => User::class,
                'entity_id' => $driver->user_id,
                'old_value' => null,
                'new_value' => [
                    'full_name' => $driver->full_name,
                    'phone' => $driver->phone,
                    'email' => $driver->email,
                ],
                'description' => "Driver {$driver->full_name} (ID: {$driver->user_id}) created by {$actor->full_name} (ID: {$actor->user_id}).",
            ]);

            return [
                'driver' => $driver->load('roles'),
                'driver_profile' => $driverProfile,
                'vehicle' => $vehicle->load('images'),
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
        if (!$file) {
            return null;
        }

        return $this->storeFile($file, $directory);
    }

    private function toPublicStoragePath(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        return 'storage/'.$path;
    }

    public function toggleStatus(int $id, User $actor): User
    {
        $user = $this->getDriver($id);

        $oldStatus = $user->account_status;
        $newStatus = $oldStatus === User::STATUS_ACTIVE ? User::STATUS_INACTIVE : User::STATUS_ACTIVE;

        $user->update(['account_status' => $newStatus]);

        AuditLog::create([
            'actor_user_id' => $actor->user_id,
            'action'        => 'driver.status_toggled',
            'entity_type'   => User::class,
            'entity_id'     => $user->user_id,
            'old_value'     => ['account_status' => $oldStatus],
            'new_value'     => ['account_status' => $newStatus],
            'description'   => "Driver {$user->full_name} (ID: {$user->user_id}) status changed from {$oldStatus} to {$newStatus} by {$actor->full_name} (ID: {$actor->user_id}).",
        ]);

        return $user->fresh(['roles', 'driverProfile.vehicles.images']);
    }
}
