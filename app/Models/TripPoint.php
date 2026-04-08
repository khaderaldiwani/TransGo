<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TripPoint extends Model
{
    protected $table = 'trip_points';
    protected $primaryKey = 'point_id';

    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'trip_id',
        'point_type',
        'latitude',
        'longitude',
        'address',
        'sequence_order',
    ];

    protected $casts = [
        'point_id' => 'integer',
        'trip_id' => 'integer',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'sequence_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function trip()
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }

    public function bookingPickupPoints()
    {
        return $this->hasMany(BookingPickupPoint::class, 'trip_point_id', 'point_id');
    }
}
