<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;
    
    
        protected $fillable = [
        'menu_id',
        'note',
        'order_info',
        'car_color',
        'phone',
        'car_size',
        'status', 
        'payment_method',
        'payment_id', 
        'total_price',
        ];


    public function menu() {
        return $this->belongsTo(Menu::class);
    }
}
