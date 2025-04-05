<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'user_id',
        'plan_id',
        'cors_id',
        'subscription_id',
        'amount',
        'payment_reference',
        'status',
        'payment_method',
        'payment_details'
    ];

    protected $casts = [
        'payment_details' => 'array'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function cors(): BelongsTo
    {
        return $this->belongsTo(Cors::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
