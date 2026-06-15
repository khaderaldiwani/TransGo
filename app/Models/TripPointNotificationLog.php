<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TripPointNotificationLog extends Model
{
    protected $table = 'trip_point_notification_logs';
    protected $primaryKey = 'trip_point_notification_log_id';

    protected $fillable = [
        'trip_id',
        'point_id',
        'notification_type',
        'triggered_at',
    ];

    protected $casts = [
        'trip_id' => 'integer',
        'point_id' => 'integer',
        'triggered_at' => 'datetime',
    ];
}
