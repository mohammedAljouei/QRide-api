<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AddOnTitle extends Model
{
    use HasFactory;


    protected $fillable = [
        'meal_id',
        'title',
        'min',
        'max',

    
        // other attributes...
    ];


    public function meal() {
        return $this->belongsTo(Meal::class);
    }


    public function addOnInfos() {
        return $this->hasMany(AddOnInfo::class);
    }
}
