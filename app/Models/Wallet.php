<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    protected $table = 'wallets';
    protected $primaryKey = 'wallet_id';

    protected $fillable = [
        'user_id',
        'balance',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'balance' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function transactions()
    {
        return $this->hasMany(WalletTransaction::class, 'wallet_id', 'wallet_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'wallet_id', 'wallet_id');
    }

    public function receipts()
    {
        return $this->hasMany(Receipt::class, 'wallet_id', 'wallet_id');
    }
}
