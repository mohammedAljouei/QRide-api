<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormDetails extends Model
{
    protected $table = 'form_details';
    
    protected $fillable = [
        'name',
        'phone',
        'resName',
    ];
}