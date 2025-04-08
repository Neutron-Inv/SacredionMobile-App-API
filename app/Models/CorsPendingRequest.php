<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CorsPendingRequest extends Model
{
    protected $fillable = [
        'token',
        'user_id',
        'cors_id',
        'plan_id',
        'status',
        'request_url',
        'response',
        'expires_at'
    ];

    protected $casts = [
        'expires_at' => 'datetime'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function cors()
    {
        return $this->belongsTo(Cors::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public static function generateToken()
    {
        return Str::random(32);
    }
}
