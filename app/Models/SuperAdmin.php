<?php

// namespace App\Models;

// use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Illuminate\Database\Eloquent\Model;

// class SuperAdmin extends Model
// {
//     use HasFactory;

//     protected $fillable = [
//         "name",
//     ];





//     public function menu() {
//         return $this->hasOne(Menu::class);
//     }

//     public function shopUI() {
//         return $this->hasOne(ShopUI::class);
//     }
// }


namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Hash;

class SuperAdmin extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $fillable = [
        "name",
        "email", 
        "password", 
    ];

    public function setPasswordAttribute($value)
    {
        $this->attributes['password'] = Hash::make($value);
    }

    // Relationships
    public function menu() {
        return $this->hasOne(Menu::class);
    }

    public function shopUI() {
        return $this->hasOne(ShopUI::class);
    }

    // Other methods and properties...
}
