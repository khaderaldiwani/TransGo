<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VehicleImage extends Model
{
    public $timestamps = false;
    protected $table = 'vehicle_images';
    // ==================== Fillable ====================
    protected $fillable = [
        'vehicle_id',
        'image_url',
    ];

    public function vehicle()
    {
        
        return $this->belongsTo(Vehicle::class);
    }
}
