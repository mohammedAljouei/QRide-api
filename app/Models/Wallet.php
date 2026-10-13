<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// One wallet per customer per shop. The balance is the sum of its posted transactions.
class Wallet extends Model
{
    protected $fillable = [
        'menu_id',
        'customer_id',
    ];

    public function customer() {
        return $this->belongsTo(Customer::class);
    }

    public function menu() {
        return $this->belongsTo(Menu::class);
    }

    public function transactions() {
        return $this->hasMany(WalletTransaction::class);
    }

    public function balance(): float
    {
        return round((float) $this->transactions()->where('status', 'posted')->sum('amount'), 2);
    }
}
