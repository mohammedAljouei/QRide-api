<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// v2: a cashier's request to deduct from a customer's wallet; the customer confirms it on their phone
class CounterCharge extends Model
{
    protected $fillable = ['menu_id', 'customer_id', 'admin_id', 'amount', 'status', 'expires_at'];

    protected $casts = ['amount' => 'float', 'expires_at' => 'datetime'];
}
