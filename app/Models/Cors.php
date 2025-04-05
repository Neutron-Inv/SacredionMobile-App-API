<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cors extends Model
{
    protected $table = "cors";
    protected $fillable = [
        'name',
        'location',
        'username',
        'password',
        'url',
        'station_list',
    ];

    protected $hidden = [
        'username',
        'password',
        'url',
        'station_list',
    ];
}
