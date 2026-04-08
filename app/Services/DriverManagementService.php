<?php

namespace App\Services;
use App\Models\AuditLog;
use App\Models\DriverProfile;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class DriverManagementService
{
    public function createDriver(array $data, User $actor): array
    {
        if (!$actor->hasAnyRole([Role::ROLE_ADMIN, Role::ROLE_EMPLOYEE])) {
            throw new RuntimeException('Forbidden.', 403);
        }

        return DB::transaction(function () use ($data, $actor) {
            $temporaryPassword = Str::random(10);
            $idCardImage = $this->storeFile($data['id_card_image'], 'drivers/id-cards');
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
                'id_card_image' => $this->toPublicStoragePath($idCardImage),
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
}
