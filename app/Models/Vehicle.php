<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    public $timestamps = false;
     // ==================== Fillable ====================
    protected $fillable = [
        'driver_id',
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

    // 🔹 Images
    public function images()
    {
        return $this->hasMany(VehicleImage::class);
    }

}
