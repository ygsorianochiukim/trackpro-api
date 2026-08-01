<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable([
    'reference', 'customer_id', 'subscription_plan_id', 'order_id',
    'plan_name', 'billing_period', 'quantity', 'unit_price', 'price',
    'status', 'starts_at', 'renews_at', 'auto_renew',
    'last_paid_at', 'cancelled_at', 'notes',
])]
class Subscription extends Model
{
    /** Days a customer keeps service after the renewal date lapses. */
    public const GRACE_DAYS = 15;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'price' => 'integer',
            'auto_renew' => 'boolean',
            'starts_at' => 'date',
            'renews_at' => 'date',
            'last_paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected $appends = ['days_until_renewal', 'is_due'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class)->latest('period_start');
    }

    /** Negative once the renewal date has passed. */
    public function getDaysUntilRenewalAttribute(): int
    {
        return Carbon::today()->diffInDays($this->renews_at, false);
    }

    /** True when the renewal is inside the billing window (or already lapsed). */
    public function getIsDueAttribute(): bool
    {
        return $this->days_until_renewal <= SubscriptionInvoice::BILL_AHEAD_DAYS;
    }

    /** The open (payable) invoice, if one has been raised. */
    public function openInvoice(): ?SubscriptionInvoice
    {
        return $this->invoices()
            ->whereIn('status', ['unpaid', 'awaiting'])
            ->orderBy('due_date')
            ->first();
    }

    /** Generate a friendly reference, e.g. SUB-000123 (skips any already taken). */
    public static function nextReference(): string
    {
        $n = (int) (static::max('id') ?? 0) + 1;
        do {
            $reference = 'SUB-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
            $n++;
        } while (static::where('reference', $reference)->exists());

        return $reference;
    }
}
