<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Meal extends Model
{
    use HasFactory;



    protected $fillable = [
        'section_id',
        'description',
        'sort',
        'status',
        'image_path',
        'name',
        'price'
        // other attributes...
    ];


    public function section() {
        return $this->belongsTo(Section::class);
    }

    public function addOnTitles() {
        return $this->hasMany(AddOnTitle::class);
    }
}
