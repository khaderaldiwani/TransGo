<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['phone' => '0938800414'],
            
            [
                'full_name' => 'khader',
                'email' => 'khader.diwani@gmail.com',
                'password' => Hash::make('12345678'),
                'must_change_password' => false,
                'account_status' => User::STATUS_ACTIVE,
                'registration_type' => User::REGISTRATION_ADMIN,
            ]
        );

        $adminRole = Role::where('name', Role::ROLE_ADMIN)->first();

        if (!$adminRole) {
            $adminRole = Role::create(['name' => Role::ROLE_ADMIN]);
        }

        $admin->roles()->syncWithoutDetaching([$adminRole->id]);
    }
}
