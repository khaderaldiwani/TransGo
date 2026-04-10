<?php

namespace Database\Seeders;

use App\Models\DriverProfile;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DriverSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $driverRole = Role::where('name', Role::ROLE_DRIVER)->first();

        if (! $driverRole) {
            $driverRole = Role::create(['name' => Role::ROLE_DRIVER]);
        }

        $drivers = [
            [
                'full_name' => 'Hassan Faris',
                'phone' => '0932000001',
                'email' => 'hassan.driver@example.com',
                'address' => 'Damascus, Syria',
            ],
            [
                'full_name' => 'Nour Eid',
                'phone' => '0932000002',
                'email' => 'nour.driver@example.com',
                'address' => 'Aleppo, Syria',
            ],
        ];

        foreach ($drivers as $data) {
            $driver = User::updateOrCreate(
                ['phone' => $data['phone']],
                [
                    'full_name' => $data['full_name'],
                    'email' => $data['email'],
                    'password' => Hash::make('Driver123!'),
                    'must_change_password' => false,
                    'account_status' => User::STATUS_ACTIVE,
                    'registration_type' => User::REGISTRATION_SELF,
                ]
            );

            $driver->roles()->syncWithoutDetaching([$driverRole->id]);

            $profile = DriverProfile::updateOrCreate(
                ['user_id' => $driver->user_id],
                [
                    'address' => $data['address'],
                    'id_card_image' => 'storage/drivers/id-cards/sample-id.jpg',
                    'license_image' => 'storage/drivers/licenses/sample-license.jpg',
                    'personal_photo' => 'storage/drivers/personal-photos/sample-photo.jpg',
                    'approval_status' => DriverProfile::APPROVAL_APPROVED,
                ]
            );

            Vehicle::updateOrCreate(
                ['driver_id' => $driver->user_id],
                [
                    'seat_capacity' => 4,
                    'car_type' => 'Sedan',
                    'mechanical_car' => 'storage/vehicles/mechanical/sample-mechanical.jpg',
                    'insurance_image' => 'storage/vehicles/insurance/sample-insurance.jpg',
                    'ownership_document' => 'storage/vehicles/ownership-documents/sample-ownership.jpg',
                    'certified_agency' => 'storage/vehicles/certified-agency/sample-certified.jpg',
                ]
            );
        }
    }
}
