<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $table = 'transactions';

    protected $fillable = [
        'user_id', 'wallet_id', 'txid', 'type', 'status',
        'currency', 'amount', 'fee', 'to_address', 'from_address',
        'confirmations', 'meta', 'completed_at'
    ];

    protected $casts = [
        'meta' => 'array',
        'completed_at' => 'datetime',
    ];

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }
}
