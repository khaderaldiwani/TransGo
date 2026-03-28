<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20', 'unique:users,phone'],
            'address' => ['required', 'string', 'max:500'],
            'id_card_image' => ['required', 'image', 'max:5120'],
            'license_image' => ['required', 'image', 'max:5120'],
            'personal_photo' => ['required', 'image', 'max:5120'],
            'car_type' => ['required', 'string', 'max:255'],
            'mechanical_car' => ['required', 'image', 'max:5120'],
            'vehicle_images' => ['required', 'array', 'size:4'],
            'vehicle_images.*' => ['required', 'image', 'max:5120'],
            'insurance_image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'ownership_document' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'certified_agency' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }
}
