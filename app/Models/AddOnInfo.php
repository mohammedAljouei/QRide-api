<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AddOnInfo extends Model
{
    use HasFactory;


    public function addOnTitle() {
        return $this->belongsTo(AddOnTitle::class);
    }
}
