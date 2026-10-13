<?php

namespace App\Models;

use App\Models\Concerns\HasMedia;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'slug', 'name', 'subtitle', 'price', 'icon', 'description',
    'features', 'devices', 'media', 'warranty', 'sort_order', 'is_active',
])]
class AddOn extends Model
{
    use HasMedia;

    protected $attributes = [
        'warranty' => '1 Year Warranty',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'features' => 'array',
            'devices' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
