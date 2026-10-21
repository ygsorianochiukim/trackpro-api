<?php

namespace App\Models;

use App\Models\Concerns\HasMedia;
use App\Support\HardwareOptions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'slug', 'name', 'model', 'category', 'icon',
    'price', 'subscription', 'tagline', 'description',
    'highlights', 'media', 'featured', 'stock', 'is_active',
    'hardware_options', 'add_on_slugs', 'offers_bandwidth', 'bandwidth_options', 'requires_plan',
])]
class Product extends Model
{
    use HasMedia;

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'subscription' => 'integer',
            'stock' => 'integer',
            'highlights' => 'array',
            'featured' => 'boolean',
            'is_active' => 'boolean',
            'add_on_slugs' => 'array',
            'offers_bandwidth' => 'boolean', // null = default (offered)
            'bandwidth_options' => 'array',
            'requires_plan' => 'boolean',
        ];
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * [{name, price, image}] — `image` is an uploaded path; `image_url` is
     * added on read (like `media`) and never stored.
     */
    protected function hardwareOptions(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? null : HardwareOptions::withImageUrls(json_decode($value, true) ?? []),
            set: fn (?array $value) => $value === null ? null : json_encode(array_values(array_map(
                fn (array $o) => ['name' => $o['name'], 'price' => (int) $o['price'], 'image' => $o['image'] ?? null],
                $value
            ))),
        );
    }
}
