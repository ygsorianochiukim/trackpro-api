<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'slug', 'name', 'description', 'price', 'billing_period',
    'features', 'is_default', 'is_active',
])]
class SubscriptionPlan extends Model
{
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'features' => 'array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** The plan used when provisioning a subscription from a paid order. */
    public static function default(): ?self
    {
        return static::where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('price')
            ->first();
    }
}
