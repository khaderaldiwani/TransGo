<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminProfileResource;
use App\Http\Resources\ApiResponse;
use Illuminate\Http\Request;
use Throwable;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        try {
            $user = $request->user();

            return ApiResponse::success(
                'Admin profile retrieved successfully.',
                200,
                new AdminProfileResource([
                    'photo' => data_get($user, 'profile_photo'),
                    'name' => data_get($user, 'full_name'),
                    'email' => data_get($user, 'email'),
                    'phone_number' => data_get($user, 'phone'),
                ])
            );
        } catch (Throwable $e) {
            return ApiResponse::error('Unexpected error while retrieving admin profile.', 500);
        }
    }
}
