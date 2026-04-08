<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingCancellation extends Model
{
    protected $table = 'booking_cancellations';
    protected $primaryKey = 'cancellation_id';

    protected $fillable = [
        'booking_id',
        'canceled_by',
        'reason',
        'cancellation_time',
        'hours_before_departure',
        'penalty_percentage',
        'penalty_amount',
        'wallet_refund_amount',
        'rating_penalty',
    ];

    protected $casts = [
        'booking_id' => 'integer',
        'canceled_by' => 'integer',
        'cancellation_time' => 'datetime',
        'hours_before_departure' => 'decimal:2',
        'penalty_percentage' => 'decimal:2',
        'penalty_amount' => 'decimal:2',
        'wallet_refund_amount' => 'decimal:2',
        'rating_penalty' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'canceled_by', 'user_id');
    }
}
