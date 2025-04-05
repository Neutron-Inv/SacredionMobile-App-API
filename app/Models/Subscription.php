<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Cors;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $table = "subscriptions";
    protected $fillable = [
        'user_id',
        'cors_id',
        'plan_id',
        'payment_reference',
        'expiry_date',
        'user_limit',
        'days_limit',
    ];

    public function cors()
    {
        return $this->belongsTo((Cors::class));
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }
}
