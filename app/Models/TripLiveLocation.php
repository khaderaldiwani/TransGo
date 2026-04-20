<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TripLiveLocation extends Model
{
    protected $table = 'trip_live_locations';
    protected $primaryKey = 'location_id';

    public $incrementing = true;
    protected $keyType = 'int';

    public $timestamps = true;

    protected $fillable = [
        'trip_id',
        'driver_id',
        'latitude',
        'longitude',
        'speed_kmh',
        'heading',
        'accuracy_meters',
        'recorded_at',
    ];

    protected $casts = [
        'location_id' => 'integer',
        'trip_id' => 'integer',
        'driver_id' => 'integer',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'speed_kmh' => 'decimal:2',
        'heading' => 'decimal:2',
        'accuracy_meters' => 'decimal:2',
        'recorded_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function trip()
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id', 'user_id');
    }
}
