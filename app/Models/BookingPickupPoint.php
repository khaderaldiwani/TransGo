<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingPickupPoint extends Model
{
    protected $table = 'booking_pickup_points';
    protected $primaryKey = 'pickup_point_id';

    protected $fillable = [
        'booking_id',
        'trip_point_id',
        'governorate_id',
        'point_name',
        'address',
        'latitude',
        'longitude',
        'meeting_time',
        'is_new',
    ];

    protected $casts = [
        'booking_id' => 'integer',
        'trip_point_id' => 'integer',
        'governorate_id' => 'integer',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'meeting_time' => 'datetime',
        'is_new' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    public function tripPoint()
    {
        return $this->belongsTo(TripPoint::class, 'trip_point_id', 'point_id');
    }

    public function governorate()
    {
        return $this->belongsTo(Governorate::class, 'governorate_id', 'governorate_id');
    }
}
