<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingAttendance extends Model
{
    protected $table = 'booking_attendances';
    protected $primaryKey = 'attendance_id';

    protected $fillable = [
        'booking_id',
        'status_id',
        'marked_by',
        'marked_at',
        'penalty_amount',
        'rating_penalty',
        'notes',
    ];

    protected $casts = [
        'booking_id' => 'integer',
        'status_id' => 'integer',
        'marked_by' => 'integer',
        'marked_at' => 'datetime',
        'penalty_amount' => 'decimal:2',
        'rating_penalty' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    public function status()
    {
        return $this->belongsTo(BookingAttendanceStatus::class, 'status_id', 'status_id');
    }

    public function marker()
    {
        return $this->belongsTo(User::class, 'marked_by', 'user_id');
    }
}
