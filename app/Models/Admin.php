<?php

// namespace App\Models;

// use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Illuminate\Database\Eloquent\Model;

// class Admin extends Model
// {
//     use HasFactory;

//     public function menu() {
//         return $this->hasOne(Menu::class);
//     }
// }



namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Hash;

class Admin extends Authenticatable
{
    use HasApiTokens, HasFactory;

    // QRide admin panel: the encrypted copy of the password is never sent by the normal APIs
    protected $hidden = ['panel_password'];

    protected $fillable = [
        'name',
        'email',
        'password',
        // other attributes...
    ];

    /**
 * Automatically hash the password when it is set.
 *
 * @param  string  $value
 * @return void
 */
public function setPasswordAttribute($value)
{
    $this->attributes['password'] = Hash::make($value);
}


    // Your relationships and other methods...

    public function menu() {
        return $this->hasOne(Menu::class);
    }
}