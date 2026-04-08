<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $table = 'notifications';
    protected $primaryKey = 'notification_id';

    protected $fillable = [
        'title',
        'body',
        'notification_type',
        'reference_type',
        'reference_id',
        'created_by',
        'target_role',
        'target_governorate_id',
    ];

    protected $casts = [
        'reference_id' => 'integer',
        'created_by' => 'integer',
        'target_governorate_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function governorate()
    {
        return $this->belongsTo(Governorate::class, 'target_governorate_id', 'governorate_id');
    }

    public function userNotifications()
    {
        return $this->hasMany(UserNotification::class, 'notification_id', 'notification_id');
    }
}
