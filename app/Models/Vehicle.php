<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    public $timestamps = false;
     // ==================== Fillable ====================
    protected $fillable = [
        'driver_id',
        'vehicle_category_id',
        'seat_capacity',
        'car_type',
        'mechanical_car',
        'insurance_image',
        'ownership_document',
        'certified_agency',
    ];

 
    // 🔹 Driver
    public function driver()
    {
        return $this->belongsTo(DriverProfile::class, 'driver_id');
    }

    public function category()
    {
        return $this->belongsTo(VehicleCategory::class, 'vehicle_category_id', 'category_id');
    }

    public function categoryPayload(): ?array
    {
        if (! $this->category) {
            return null;
        }

        return [
            'category_id' => $this->category->category_id,
            'name' => $this->category->name,
            'price_per_km' => (float) $this->category->price_per_km,
            'is_active' => (bool) $this->category->is_active,
        ];
    }

    // 🔹 Images
    public function images()
    {
        return $this->hasMany(VehicleImage::class);
    }

}
