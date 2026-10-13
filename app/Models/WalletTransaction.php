<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletTransaction extends Model
{
    protected $fillable = [
        'wallet_id',
        'type',
        'amount',
        'status',
        'source_type',
        'source_id',
    ];

    protected $casts = [
        'amount' => 'float',
    ];

    public function wallet() {
        return $this->belongsTo(Wallet::class);
    }
}
