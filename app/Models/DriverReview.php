<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DriverReview extends Model
{
    protected $table = 'driver_reviews';
    protected $primaryKey = 'review_id';

    protected $fillable = [
        'booking_id',
        'driver_id',
        'passenger_id',
        'rating',
        'comment',
    ];

    protected $casts = [
        'booking_id' => 'integer',
        'driver_id' => 'integer',
        'passenger_id' => 'integer',
        'rating' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id', 'user_id');
    }

    public function passenger()
    {
        return $this->belongsTo(User::class, 'passenger_id', 'user_id');
    }
}
