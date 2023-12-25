<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopUI extends Model
{
    use HasFactory;

    public function superAdmin() {
        return $this->belongsTo(SuperAdmin::class);
    }

}
