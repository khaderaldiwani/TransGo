<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DriverProfile extends Model
{
    public $timestamps = false;



    // ==================== Table Name ====================
    protected $table = 'driver_profiles';
    
    // ==================== Primary Key ====================
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'int';
     
    // ==================== Constants ====================
    public const APPROVAL_PENDING = 'pending';
    public const APPROVAL_APPROVED = 'approved';
    public const APPROVAL_REJECTED = 'rejected';
    // ==================== Fillable ====================
    protected $fillable = [
        'user_id',
        'address',
        'id_card',
        'license_image',
        'personal_photo',
        'approval_status',
    ];
    // 🔹 User
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // 🔹 Vehicles
    public function vehicles()
    {
        return $this->hasMany(Vehicle::class, 'driver_id');
    }

    public function trips()
    {
        return $this->hasMany(Trip::class, 'driver_id', 'user_id');
    }
}
