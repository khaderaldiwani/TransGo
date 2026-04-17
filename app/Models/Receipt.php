<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Receipt extends Model
{
    protected $table = 'receipts';
    protected $primaryKey = 'receipt_id';

    protected $fillable = [
        'receipt_number',
        'owner_user_id',
        'wallet_id',
        'related_wallet_transaction_id',
        'related_payment_id',
        'related_booking_id',
        'related_trip_id',
        'commission_rate_id',
        'receipt_type',
        'direction',
        'status',
        'amount',
        'counterparty_user_id',
        'counterparty_name',
        'reason',
        'gross_amount',
        'commission_percentage',
        'commission_amount',
        'net_amount',
        'metadata',
    ];

    protected $casts = [
        'owner_user_id' => 'integer',
        'wallet_id' => 'integer',
        'related_wallet_transaction_id' => 'integer',
        'related_payment_id' => 'integer',
        'related_booking_id' => 'integer',
        'related_trip_id' => 'integer',
        'commission_rate_id' => 'integer',
        'amount' => 'decimal:2',
        'counterparty_user_id' => 'integer',
        'gross_amount' => 'decimal:2',
        'commission_percentage' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id', 'user_id');
    }

    public function wallet()
    {
        return $this->belongsTo(Wallet::class, 'wallet_id', 'wallet_id');
    }

    public function walletTransaction()
    {
        return $this->belongsTo(WalletTransaction::class, 'related_wallet_transaction_id', 'transaction_id');
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class, 'related_payment_id', 'payment_id');
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'related_booking_id', 'booking_id');
    }

    public function trip()
    {
        return $this->belongsTo(Trip::class, 'related_trip_id', 'trip_id');
    }

    public function commissionRate()
    {
        return $this->belongsTo(CommissionRate::class, 'commission_rate_id', 'commission_rate_id');
    }

    public function counterparty()
    {
        return $this->belongsTo(User::class, 'counterparty_user_id', 'user_id');
    }
}
