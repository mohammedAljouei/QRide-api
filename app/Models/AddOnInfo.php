<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AddOnInfo extends Model
{
    use HasFactory;

    protected $fillable = [
        'add_on_title_id',
        'name',
        'price',
        // other attributes...
    ];

    public function addOnTitle() {
        return $this->belongsTo(AddOnTitle::class);
    }
}
