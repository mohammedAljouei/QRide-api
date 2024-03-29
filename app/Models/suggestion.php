<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class suggestion extends Model
{
    use HasFactory;


    protected $fillable = [
        'order_id',
        'message',
        'star',
        ];

        
    public function order() {
        return $this->belongsTo(Order::class);
    }
}
