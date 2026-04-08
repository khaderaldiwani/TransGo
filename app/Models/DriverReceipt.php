<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DriverReceipt extends Model
{
    protected $table = 'driver_receipts';
    protected $primaryKey = 'receipt_id';

    public $timestamps = false;

    protected $fillable = [
        'receipt_number',
        'trip_id',
        'driver_id',
        'commission_rate_id',
        'gross_amount',
        'commission_percentage',
        'commission_amount',
        'net_amount',
        'status',
        'created_at',
    ];

    protected $casts = [
        'trip_id' => 'integer',
        'driver_id' => 'integer',
        'commission_rate_id' => 'integer',
        'gross_amount' => 'decimal:2',
        'commission_percentage' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function trip()
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id', 'user_id');
    }

    public function commissionRate()
    {
        return $this->belongsTo(CommissionRate::class, 'commission_rate_id', 'commission_rate_id');
    }

    public function walletTransactions()
    {
        return $this->hasMany(WalletTransaction::class, 'related_receipt_id', 'receipt_id');
    }
}
