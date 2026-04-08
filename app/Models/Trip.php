<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Trip extends Model
{
    protected $table = 'trips';
    protected $primaryKey = 'trip_id';

    public $incrementing = true;
    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'driver_id',
        'start_governorate_id',
        'end_governorate_id',
        'departure_time',
        'estimated_duration_minutes',
        'estimated_distance_km',
        'total_seats',
        'available_seats',
        'allow_shared',
        'allow_private',
        'is_private_booked',
        'shared_price',
        'private_price',
        'system_calculated_price',
        'route_polyline',
        'status_id',
        'created_at',
    ];

    protected $casts = [
        'trip_id' => 'integer',
        'driver_id' => 'integer',
        'start_governorate_id' => 'integer',
        'end_governorate_id' => 'integer',
        'departure_time' => 'datetime',
        'estimated_duration_minutes' => 'integer',
        'estimated_distance_km' => 'decimal:2',
        'total_seats' => 'integer',
        'available_seats' => 'integer',
        'allow_shared' => 'boolean',
        'allow_private' => 'boolean',
        'is_private_booked' => 'boolean',
        'shared_price' => 'decimal:2',
        'private_price' => 'decimal:2',
        'system_calculated_price' => 'decimal:2',
        'route_polyline' => 'string',
        'status_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function driver()
    {
        return $this->belongsTo(DriverProfile::class, 'driver_id', 'user_id');
    }

    public function startGovernorate()
    {
        return $this->belongsTo(Governorate::class, 'start_governorate_id', 'governorate_id');
    }

    public function endGovernorate()
    {
        return $this->belongsTo(Governorate::class, 'end_governorate_id', 'governorate_id');
    }

    public function status()
    {
        return $this->belongsTo(TripStatus::class, 'status_id', 'status_id');
    }

    public function points()
    {
        return $this->hasMany(TripPoint::class, 'trip_id', 'trip_id')->orderBy('sequence_order');
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class, 'trip_id', 'trip_id');
    }

    public function receipts()
    {
        return $this->hasMany(DriverReceipt::class, 'trip_id', 'trip_id');
    }

    public function complaints()
    {
        return $this->hasMany(Complaint::class, 'related_trip_id', 'trip_id');
    }
}
