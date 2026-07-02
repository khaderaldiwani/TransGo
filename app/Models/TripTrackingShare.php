<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TripTrackingShare extends Model
{
    protected $table = 'trip_tracking_shares';
    protected $primaryKey = 'share_id';

    protected $fillable = [
        'trip_id',
        'booking_id',
        'created_by',
        'token',
        'expires_at',
        'revoked_at',
        'last_accessed_at',
    ];

    protected $casts = [
        'share_id' => 'integer',
        'trip_id' => 'integer',
        'booking_id' => 'integer',
        'created_by' => 'integer',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function trip()
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }
}
