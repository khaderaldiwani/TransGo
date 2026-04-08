<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PassengerRatingLog extends Model
{
    protected $table = 'passenger_rating_logs';
    protected $primaryKey = 'log_id';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'booking_id',
        'rating_change',
        'reason',
        'created_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'booking_id' => 'integer',
        'rating_change' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }
}
