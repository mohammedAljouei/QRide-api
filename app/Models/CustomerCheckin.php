<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerCheckin extends Model
{
    use HasFactory;

    protected $fillable = ['order_id', 'checkin_time'];

    // Disable timestamps
    public $timestamps = false;

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
