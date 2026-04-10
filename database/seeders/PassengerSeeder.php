<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PassengerSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $passengerRole = Role::where('name', Role::ROLE_PASSENGER)->first();

        if (! $passengerRole) {
            $passengerRole = Role::create(['name' => Role::ROLE_PASSENGER]);
        }

        $passengers = [
            [
                'full_name' => 'Ali Mahmoud',
                'phone' => '0931000001',
                'email' => 'ali.passenger@example.com',
            ],
            [
                'full_name' => 'Sara Nabil',
                'phone' => '0931000002',
                'email' => 'sara.passenger@example.com',
            ],
            [
                'full_name' => 'Khaled Yassin',
                'phone' => '0931000003',
                'email' => 'khaled.passenger@example.com',
            ],
        ];

        foreach ($passengers as $data) {
            $passenger = User::updateOrCreate(
                ['phone' => $data['phone']],
                [
                    'full_name' => $data['full_name'],
                    'email' => $data['email'],
                    'password' => Hash::make('Passenger123!'),
                    'must_change_password' => false,
                    'account_status' => User::STATUS_ACTIVE,
                    'registration_type' => User::REGISTRATION_SELF,
                ]
            );

            $passenger->roles()->syncWithoutDetaching([$passengerRole->id]);
        }
    }
}
