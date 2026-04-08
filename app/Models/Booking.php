<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    protected $table = 'bookings';
    protected $primaryKey = 'booking_id';

    protected $fillable = [
        'booking_code',
        'trip_id',
        'passenger_id',
        'booking_type',
        'seats_reserved',
        'payment_method',
        'total_amount',
        'status_id',
        'attendance_status_id',
        'notes',
        'confirmed_at',
        'rejected_at',
        'canceled_at',
        'completed_at',
    ];

    protected $casts = [
        'trip_id' => 'integer',
        'passenger_id' => 'integer',
        'seats_reserved' => 'integer',
        'total_amount' => 'decimal:2',
        'status_id' => 'integer',
        'attendance_status_id' => 'integer',
        'confirmed_at' => 'datetime',
        'rejected_at' => 'datetime',
        'canceled_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function trip()
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }

    public function passenger()
    {
        return $this->belongsTo(User::class, 'passenger_id', 'user_id');
    }

    public function status()
    {
        return $this->belongsTo(BookingStatus::class, 'status_id', 'status_id');
    }

    public function attendanceStatus()
    {
        return $this->belongsTo(BookingAttendanceStatus::class, 'attendance_status_id', 'status_id');
    }

    public function pickupPoint()
    {
        return $this->hasOne(BookingPickupPoint::class, 'booking_id', 'booking_id');
    }

    public function statusLogs()
    {
        return $this->hasMany(BookingStatusLog::class, 'booking_id', 'booking_id');
    }

    public function attendance()
    {
        return $this->hasOne(BookingAttendance::class, 'booking_id', 'booking_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'booking_id', 'booking_id');
    }

    public function cancellation()
    {
        return $this->hasOne(BookingCancellation::class, 'booking_id', 'booking_id');
    }

    public function review()
    {
        return $this->hasOne(DriverReview::class, 'booking_id', 'booking_id');
    }
}
