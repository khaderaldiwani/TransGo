<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TripStatus extends Model
{
    public const PENDING = 'pending';
    public const ACTIVE = 'active';
    public const COMPLETED = 'completed';
    public const CANCELED = 'canceled';

    protected $table = 'trip_statuses';
    protected $primaryKey = 'status_id';

    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'status_key',
        'status_name',
        'description',
        'is_final',
        'display_order',
        'is_active',
    ];

    protected $casts = [
        'status_id' => 'integer',
        'is_final' => 'boolean',
        'display_order' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function trips()
    {
        return $this->hasMany(Trip::class, 'status_id', 'status_id');
    }
}
