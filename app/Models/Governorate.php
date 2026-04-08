<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Governorate extends Model
{
    protected $table = 'governorates';
    protected $primaryKey = 'governorate_id';

    public $incrementing = true;
    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'name',
        'is_active',
        'created_at',
    ];

    protected $casts = [
        'governorate_id' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function tripsFrom()
    {
        return $this->hasMany(Trip::class, 'start_governorate_id', 'governorate_id');
    }

    public function tripsTo()
    {
        return $this->hasMany(Trip::class, 'end_governorate_id', 'governorate_id');
    }
}
