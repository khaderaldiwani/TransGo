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
        'commission_rate_id',
        'commission_percentage',
        'max_commission_amount',
        'gross_revenue_amount',
        'commission_amount',
        'net_revenue_amount',
        'created_at',
        'completed_at',
        'actual_start_time',
        'is_tracking_active',
        'tracking_started_at',
        'completion_mode',
        'completion_reason',
        'tracking_stopped_at',
        'last_latitude',
        'last_longitude',
        'last_speed_kmh',
        'last_heading',
        'last_accuracy_meters',
        'last_location_at',
        'completion_latitude',
        'completion_longitude',
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
        'commission_rate_id' => 'integer',
        'commission_percentage' => 'decimal:2',
        'max_commission_amount' => 'decimal:2',
        'gross_revenue_amount' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'net_revenue_amount' => 'decimal:2',
        'created_at' => 'datetime',
        'completed_at' => 'datetime',
        'actual_start_time' => 'datetime',
        'is_tracking_active' => 'boolean',
        'tracking_started_at' => 'datetime',
        'tracking_stopped_at' => 'datetime',
        'last_latitude' => 'decimal:7',
        'last_longitude' => 'decimal:7',
        'last_speed_kmh' => 'decimal:2',
        'last_heading' => 'decimal:2',
        'last_accuracy_meters' => 'decimal:2',
        'last_location_at' => 'datetime',
        'completion_latitude' => 'decimal:7',
        'completion_longitude' => 'decimal:7',
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

    public function commissionRate()
    {
        return $this->belongsTo(CommissionRate::class, 'commission_rate_id', 'commission_rate_id');
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
        return $this->hasMany(Receipt::class, 'related_trip_id', 'trip_id');
    }

    public function complaints()
    {
        return $this->hasMany(Complaint::class, 'related_trip_id', 'trip_id');
    }

    public function liveLocations()
    {
        return $this->hasMany(TripLiveLocation::class, 'trip_id', 'trip_id');
    }
}
