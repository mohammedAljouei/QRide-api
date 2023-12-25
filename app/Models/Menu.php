<?php

// for now, a menu reprsent one shop, the id of the menu is the id of the shop

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Menu extends Model
{
    use HasFactory;

    // I wanna think about it as Shop not a Menu
    // TO DO: change the class Name to Shop



    protected $fillable = [
        'admin_id',
        'super_admin_id',
        'version',
        'status',
    
        // other attributes...
    ];


    public function admin() {
        return $this->belongsTo(Admin::class);
    }

    public function superAdmin() {
        return $this->belongsTo(SuperAdmin::class);
    }


    public function sections() {
        return $this->hasMany(Section::class);
    }


    public function suggestions() {
        return $this->hasMany(Suggestion::class);
    }


    public function orders() {
        return $this->hasMany(Order::class);
    }
}
