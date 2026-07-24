<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id', 'provider',
    'provider_payment_id', 'provider_link_id', 'payment_method',
    'amount', 'status', 'checkout_url', 'raw_payload', 'paid_at',
])]
class Payment extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'raw_payload' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
