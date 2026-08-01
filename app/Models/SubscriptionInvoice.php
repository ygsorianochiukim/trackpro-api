<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'reference', 'subscription_id', 'customer_id',
    'description', 'amount', 'period_start', 'period_end', 'due_date',
    'status', 'provider', 'provider_link_id', 'provider_payment_id',
    'payment_method', 'checkout_url', 'paid_at',
])]
class SubscriptionInvoice extends Model
{
    /** How early before the renewal date an invoice is raised. */
    public const BILL_AHEAD_DAYS = 30;

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'due_date' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isPayable(): bool
    {
        return in_array($this->status, ['unpaid', 'awaiting'], true);
    }

    /** Generate a friendly reference, e.g. INV-000123 (skips any already taken). */
    public static function nextReference(): string
    {
        $n = (int) (static::max('id') ?? 0) + 1;
        do {
            $reference = 'INV-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
            $n++;
        } while (static::where('reference', $reference)->exists());

        return $reference;
    }
}
