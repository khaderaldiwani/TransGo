<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingStatusLog extends Model
{
    protected $table = 'booking_status_logs';
    protected $primaryKey = 'log_id';

    protected $fillable = [
        'booking_id',
        'from_status_id',
        'to_status_id',
        'changed_by',
        'reason',
        'changed_at',
    ];

    protected $casts = [
        'booking_id' => 'integer',
        'from_status_id' => 'integer',
        'to_status_id' => 'integer',
        'changed_by' => 'integer',
        'changed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    public function fromStatus()
    {
        return $this->belongsTo(BookingStatus::class, 'from_status_id', 'status_id');
    }

    public function toStatus()
    {
        return $this->belongsTo(BookingStatus::class, 'to_status_id', 'status_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'changed_by', 'user_id');
    }
}
