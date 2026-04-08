<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Complaint extends Model
{
    protected $table = 'complaints';
    protected $primaryKey = 'complaint_id';

    protected $fillable = [
        'complaint_code',
        'complainant_id',
        'complainant_role',
        'complaint_type',
        'related_trip_id',
        'related_booking_id',
        'related_driver_id',
        'related_passenger_id',
        'assigned_to',
        'description',
        'status',
        'resolved_at',
    ];

    protected $casts = [
        'complainant_id' => 'integer',
        'related_trip_id' => 'integer',
        'related_booking_id' => 'integer',
        'related_driver_id' => 'integer',
        'related_passenger_id' => 'integer',
        'assigned_to' => 'integer',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function complainant()
    {
        return $this->belongsTo(User::class, 'complainant_id', 'user_id');
    }

    public function trip()
    {
        return $this->belongsTo(Trip::class, 'related_trip_id', 'trip_id');
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'related_booking_id', 'booking_id');
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'related_driver_id', 'user_id');
    }

    public function passenger()
    {
        return $this->belongsTo(User::class, 'related_passenger_id', 'user_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to', 'user_id');
    }

    public function statusLogs()
    {
        return $this->hasMany(ComplaintStatusLog::class, 'complaint_id', 'complaint_id');
    }
}
