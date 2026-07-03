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
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'address' => ['required', 'string', 'max:500'],
            'id_card' => ['required', 'string', 'max:5120'],//رقم السيارة
            'license_image' => ['required', 'image', 'max:5120'],//رخصة القيادة
            'personal_photo' => ['required', 'image', 'max:5120'],//صورة شخصية
            'car_type' => ['required', 'string', 'max:255'],//نوع السيارة
            'vehicle_category_id' => ['nullable', 'integer', 'exists:vehicle_categories,category_id'],
            'seat_capacity' => ['required', 'integer', 'min:1'],//عدد المقاعد
            'mechanical_car' => ['required', 'image', 'max:5120'],//صورة ميكانيك السيارة
            'vehicle_images' => ['required', 'array', 'size:4'],
            'vehicle_images.*' => ['required', 'image', 'max:5120'],
            'insurance_image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],//صورة التأمين
            'ownership_document' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],//صورة ملكية السيارة
            'certified_agency' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],//صورة وكالة معتمدة
        ];
    }
}
