<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'slug', 'name', 'model', 'category', 'icon',
    'price', 'subscription', 'tagline', 'description',
    'highlights', 'featured', 'stock', 'is_active',
])]
class Product extends Model
{
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'subscription' => 'integer',
            'stock' => 'integer',
            'highlights' => 'array',
            'featured' => 'boolean',
            'is_active' => 'boolean',
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
}
