<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// v2: a receipt a customer scanned to earn cashback at the counter
class ReceiptClaim extends Model
{
    protected $fillable = ['menu_id', 'customer_id', 'fingerprint', 'vat_number', 'invoice_at', 'total', 'cashback_percent', 'cashback_amount', 'order_id'];

    protected $casts = ['invoice_at' => 'datetime', 'total' => 'float', 'cashback_percent' => 'float', 'cashback_amount' => 'float'];
}
