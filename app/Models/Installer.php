<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A technician who fits GPS devices, measured from a pinned home base. */
#[Fillable([
    'name', 'phone', 'email', 'skills',
    'base_address', 'base_lat', 'base_lng', 'service_radius_km', 'daily_capacity',
    'is_active', 'notes',
])]
class Installer extends Model
{
    protected function casts(): array
    {
        return [
            'base_lat' => 'float',
            'base_lng' => 'float',
            'service_radius_km' => 'integer',
            'daily_capacity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function installations(): HasMany
    {
        return $this->hasMany(Installation::class);
    }

    public function isPinned(): bool
    {
        return $this->base_lat !== null && $this->base_lng !== null;
    }
}
