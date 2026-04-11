<?php

namespace App\Services;

use App\Models\Wallet;
use App\Models\WalletTransaction;

class WalletTransactionService
{
    public function createForWallet(Wallet $wallet, array $attributes): WalletTransaction
    {
        return WalletTransaction::create(array_merge($attributes, [
            'wallet_id' => $wallet->wallet_id,
        ]));
    }

    public function generateReference(string $prefix = 'WLT-TOP'): string
    {
        return $prefix.'-'.now()->format('YmdHis').'-'.strtoupper(substr(uniqid(), -6));
    }
}
