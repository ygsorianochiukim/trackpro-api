<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'reference', 'customer_id',
    'customer_name', 'customer_email', 'customer_phone',
    'delivery_address', 'notes',
    'hardware_subtotal', 'subscription_total',
    'status', 'payment_status',
    'paid_at', 'shipped_at',
])]
class Order extends Model
{
    protected function casts(): array
    {
        return [
            'hardware_subtotal' => 'integer',
            'subscription_total' => 'integer',
            'paid_at' => 'datetime',
            'shipped_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** Generate a friendly reference, e.g. TP-000123 */
    public static function nextReference(): string
    {
        $lastId = (int) (static::max('id') ?? 0);
        return 'TP-' . str_pad((string) ($lastId + 1), 6, '0', STR_PAD_LEFT);
    }
}
