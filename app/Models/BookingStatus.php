<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingStatus extends Model
{
    protected $table = 'booking_statuses';
    protected $primaryKey = 'status_id';

    protected $fillable = [
        'status_key',
        'status_name',
        'description',
        'is_final',
        'display_order',
        'is_active',
    ];

    protected $casts = [
        'is_final' => 'boolean',
        'display_order' => 'integer',
        'is_active' => 'boolean',
    ];
}
