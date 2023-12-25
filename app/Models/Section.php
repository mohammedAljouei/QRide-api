<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Section extends Model
{
    use HasFactory;



    protected $fillable = [
        'menu_id',
        'name',
        'sort',

    
        // other attributes...
    ];



    public function menu() {
        return $this->belongsTo(Menu::class);
    }

    public function meals() {
        return $this->hasMany(Meal::class);
    }
}
