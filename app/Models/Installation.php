<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;

/** One GPS installation job. `status` is its kanban column; `position` its order within it. */
#[Fillable([
    'reference', 'title', 'customer_id', 'order_id', 'customer_name', 'customer_phone', 'vehicle', 'units',
    'address', 'lat', 'lng',
    'scheduled_on', 'start_time', 'duration_minutes', 'installer_id',
    'priority', 'status', 'position', 'started_at', 'completed_at', 'notes',
])]
class Installation extends Model
{
    public const STATUSES = ['pending', 'scheduled', 'in_progress', 'completed', 'cancelled'];

    public const OPEN = ['pending', 'scheduled', 'in_progress'];

    public const PRIORITIES = ['low', 'normal', 'high'];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'units' => 'integer',
            'scheduled_on' => 'date:Y-m-d',
            'duration_minutes' => 'integer',
            'position' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function installer(): BelongsTo
    {
        return $this->belongsTo(Installer::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN);
    }

    public function isPinned(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }

    /** [start, end] in minutes after midnight, or null when no start time is set. */
    public function window(): ?array
    {
        return self::windowOf($this->start_time, $this->duration_minutes);
    }

    public static function windowOf(?string $start, ?int $minutes): ?array
    {
        if ($start === null || $start === '') {
            return null;
        }

        [$h, $m] = array_map('intval', explode(':', $start));
        $from = $h * 60 + $m;

        return [$from, $from + max(15, (int) ($minutes ?? 120))];
    }

    /** The row the admin UI reads: the job plus the names it points at. */
    public function toAdminArray(): array
    {
        return Arr::except($this->toArray(), ['installer', 'order', 'customer']) + [
            'installer_name' => $this->installer?->name,
            'order_reference' => $this->order?->reference,
        ];
    }
}
