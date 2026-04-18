<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionRate extends Model
{
    protected $table = 'commission_rates';
    protected $primaryKey = 'commission_rate_id';

    protected $fillable = [
        'percentage',
        'previous_percentage',
        'effective_from',
        'effective_to',
        'is_active',
        'change_reason',
        'created_by',
    ];

    protected $casts = [
        'percentage' => 'decimal:2',
        'previous_percentage' => 'decimal:2',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
        'is_active' => 'boolean',
        'created_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function receipts()
    {
        return $this->hasMany(Receipt::class, 'commission_rate_id', 'commission_rate_id');
    }

    public function trips()
    {
        return $this->hasMany(Trip::class, 'commission_rate_id', 'commission_rate_id');
    }
}
