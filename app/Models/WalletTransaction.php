<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletTransaction extends Model
{
    protected $table = 'wallet_transactions';
    protected $primaryKey = 'transaction_id';

    protected $fillable = [
        'wallet_id',
        'related_booking_id',
        'related_receipt_id',
        'amount',
        'transaction_type',
        'status',
        'transaction_reference',
        'description',
        'balance_before',
        'balance_after',
        'performed_by',
    ];

    protected $casts = [
        'wallet_id' => 'integer',
        'related_booking_id' => 'integer',
        'related_receipt_id' => 'integer',
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'performed_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function wallet()
    {
        return $this->belongsTo(Wallet::class, 'wallet_id', 'wallet_id');
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'related_booking_id', 'booking_id');
    }

    public function receipt()
    {
        return $this->belongsTo(DriverReceipt::class, 'related_receipt_id', 'receipt_id');
    }

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by', 'user_id');
    }
}
