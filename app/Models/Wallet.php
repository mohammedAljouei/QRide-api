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

    // What the customer can spend: posted money minus holds on orders that aren't done yet
    public function balance(): float
    {
        $posted = (float) $this->transactions()->where('status', 'posted')->sum('amount');
        $held = (float) $this->transactions()->where('status', 'pending')->where('amount', '<', 0)->sum('amount');
        return round($posted + $held, 2);
    }
}
