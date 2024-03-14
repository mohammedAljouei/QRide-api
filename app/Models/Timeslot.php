<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Timeslot extends Model
{
    protected $table = 'timeslots'; // Specify the table name if it's not the plural of the model name

    protected $fillable = ['menu_id', 'day', 'start_time', 'end_time']; // Allow mass assignment for these fields

    // If you are not using Laravel's timestamps, you can disable them by adding the following line:
    public $timestamps = false;
    
    // Define the relationship with Menu model (if necessary)
    public function menu()
    {
        return $this->belongsTo('App\Models\Menu', 'menu_id', 'id');
    }
}
