<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    protected $table = 'wallets';

    protected $fillable = ['user_id', 'currency', 'balance', 'locked'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getAvailableAttribute()
    {
        return $this->balance - $this->locked;
    }
}
