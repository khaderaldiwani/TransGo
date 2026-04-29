<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TripCluster extends Model
{
    protected $table = 'trip_clusters';
    protected $primaryKey = 'cluster_id';

    protected $fillable = [
        'reference_trip_id',
        'start_governorate_id',
        'end_governorate_id',
        'reference_start_latitude',
        'reference_start_longitude',
        'reference_end_latitude',
        'reference_end_longitude',
        'reference_departure_time',
        'time_window_start',
        'time_window_end',
        'open_trips_limit',
    ];

    protected $casts = [
        'cluster_id' => 'integer',
        'reference_trip_id' => 'integer',
        'start_governorate_id' => 'integer',
        'end_governorate_id' => 'integer',
        'reference_start_latitude' => 'decimal:7',
        'reference_start_longitude' => 'decimal:7',
        'reference_end_latitude' => 'decimal:7',
        'reference_end_longitude' => 'decimal:7',
        'reference_departure_time' => 'datetime',
        'time_window_start' => 'datetime',
        'time_window_end' => 'datetime',
        'open_trips_limit' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function referenceTrip()
    {
        return $this->belongsTo(Trip::class, 'reference_trip_id', 'trip_id');
    }

    public function trips()
    {
        return $this->hasMany(Trip::class, 'cluster_id', 'cluster_id');
    }

    public function startGovernorate()
    {
        return $this->belongsTo(Governorate::class, 'start_governorate_id', 'governorate_id');
    }

    public function endGovernorate()
    {
        return $this->belongsTo(Governorate::class, 'end_governorate_id', 'governorate_id');
    }
}
