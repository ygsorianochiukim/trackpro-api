<?php

namespace App\Support;

use App\Models\Installation;
use App\Models\Installer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The rules behind booking and moving installation jobs:
 *  - a job is "scheduled" once it has both a person and a date, "pending" otherwise;
 *  - one person can't be booked into overlapping hours;
 *  - only active installers can be given new work;
 *  - kanban moves keep each column's order and stamp start / finish times.
 */
class InstallationScheduler
{
    public const PREFIX = 'INS-';

    private const EDITABLE = [
        'title', 'customer_id', 'order_id', 'customer_name', 'customer_phone', 'vehicle', 'units',
        'address', 'lat', 'lng', 'scheduled_on', 'start_time', 'duration_minutes', 'installer_id',
        'priority', 'notes',
    ];

    public function create(array $input): Installation
    {
        return DB::transaction(function () use ($input) {
            $attributes = $this->clean($input);
            $attributes['status'] = $this->derive('pending', $attributes);

            $this->guardActive($attributes['installer_id'] ?? null, null);
            $this->guardClash($attributes, null);

            $attributes['reference'] = $this->nextReference();
            $attributes['position'] = $this->bottomOf($attributes['status']);

            return $this->fresh(Installation::create($attributes));
        });
    }

    public function update(Installation $job, array $input): Installation
    {
        if (in_array($job->status, ['in_progress', 'completed'], true)
            && array_key_exists('installer_id', $input) && $input['installer_id'] === null) {
            throw ValidationException::withMessages([
                'installer_id' => 'Somebody has been on site already. Move it back to Scheduled before taking them off.',
            ]);
        }

        return DB::transaction(function () use ($job, $input) {
            $attributes = $this->clean($input);
            $merged = [
                'installer_id' => $job->installer_id,
                'scheduled_on' => $job->scheduled_on?->toDateString(),
                'start_time' => $job->start_time,
                'duration_minutes' => $job->duration_minutes,
                ...$attributes,
            ];

            $this->guardActive($merged['installer_id'], $job->installer_id);
            $this->guardClash($merged, $job->id);

            $status = $this->derive($job->status, $merged);

            if ($status !== $job->status) {
                $attributes['status'] = $status;
                $attributes['position'] = $this->bottomOf($status);
            }

            $job->update($attributes);

            return $this->fresh($job);
        });
    }

    /** Drag on the week schedule: change who and/or which day (time and length optional). */
    public function schedule(Installation $job, array $input): Installation
    {
        if (in_array($job->status, ['completed', 'cancelled'], true)) {
            throw ValidationException::withMessages([
                'status' => 'This installation is closed. Reopen it on the board before rescheduling.',
            ]);
        }

        return $this->update($job, array_intersect_key($input, array_flip([
            'installer_id', 'scheduled_on', 'start_time', 'duration_minutes',
        ])));
    }

    /** Drag on the kanban board: into `$status`, at `$index` within that column (end when null). */
    public function move(Installation $job, string $status, ?int $index = null): Installation
    {
        $this->guardStage($job, $status);

        return DB::transaction(function () use ($job, $status, $index) {
            $from = $job->status;
            $stamps = match ($status) {
                'in_progress' => ['started_at' => $job->started_at ?? now(), 'completed_at' => null],
                'completed' => ['started_at' => $job->started_at ?? now(), 'completed_at' => now()],
                'pending', 'scheduled' => ['started_at' => null, 'completed_at' => null],
                default => [],
            };

            $ids = Installation::where('status', $status)
                ->whereKeyNot($job->id)
                ->orderBy('position')->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')->all();

            $index = $index === null ? count($ids) : max(0, min($index, count($ids)));
            array_splice($ids, $index, 0, [$job->id]);

            $job->update(['status' => $status, ...$stamps]);

            foreach ($ids as $position => $id) {
                Installation::whereKey($id)->update(['position' => $position]);
            }

            if ($from !== $status) {
                $this->compact($from);
            }

            return $this->fresh($job);
        });
    }

    private function guardStage(Installation $job, string $status): void
    {
        $messages = [];

        if ($status === 'scheduled') {
            if ($job->installer_id === null) $messages['installer_id'] = 'Assign somebody first.';
            if ($job->scheduled_on === null) $messages['scheduled_on'] = 'Pick a date first.';
        }

        if (in_array($status, ['in_progress', 'completed'], true) && $job->installer_id === null) {
            $messages['installer_id'] = 'Nobody is assigned, so nobody can be on site.';
        }

        if ($status === 'pending' && $job->installer_id !== null && $job->scheduled_on !== null) {
            $messages['status'] = 'It has a date and a person, so it is scheduled. Clear one of them to send it back to the backlog.';
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    /** Pending and scheduled follow from whether a person and a date are set; later stages stay put. */
    private function derive(string $current, array $attributes): string
    {
        if (! in_array($current, ['pending', 'scheduled'], true)) {
            return $current;
        }

        return ! empty($attributes['installer_id']) && ! empty($attributes['scheduled_on']) ? 'scheduled' : 'pending';
    }

    private function guardActive(?int $installerId, ?int $current): void
    {
        if ($installerId === null || $installerId === $current) {
            return;
        }

        if (! Installer::whereKey($installerId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'installer_id' => 'That installer is inactive. Reactivate them under Personnel first.',
            ]);
        }
    }

    private function guardClash(array $attributes, ?int $ignore): void
    {
        $installer = $attributes['installer_id'] ?? null;
        $date = $attributes['scheduled_on'] ?? null;
        $window = Installation::windowOf($attributes['start_time'] ?? null, $attributes['duration_minutes'] ?? 120);

        if ($installer === null || $date === null || $window === null) {
            return;
        }

        [$start, $end] = $window;

        $clash = Installation::where('installer_id', $installer)
            ->whereDate('scheduled_on', $date)
            ->whereNotNull('start_time')
            ->where('status', '!=', 'cancelled')
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore))
            ->get()
            ->first(function (Installation $other) use ($start, $end) {
                [$s, $e] = $other->window();

                return $s < $end && $start < $e;
            });

        if ($clash) {
            $name = Installer::find($installer)?->name ?? 'That installer';

            throw ValidationException::withMessages([
                'start_time' => "{$name} is on {$clash->reference} ({$clash->title}) from {$clash->start_time} that day. Pick another time or somebody else.",
            ]);
        }
    }

    private function clean(array $input): array
    {
        $attributes = array_intersect_key($input, array_flip(self::EDITABLE));

        foreach (['title', 'customer_name', 'customer_phone', 'vehicle', 'address', 'notes'] as $key) {
            if (array_key_exists($key, $attributes) && is_string($attributes[$key])) {
                $attributes[$key] = trim($attributes[$key]) === '' ? null : trim($attributes[$key]);
            }
        }

        foreach (['installer_id', 'customer_id', 'order_id'] as $key) {
            if (array_key_exists($key, $attributes) && $attributes[$key] !== null) {
                $attributes[$key] = (int) $attributes[$key];
            }
        }

        if (! empty($attributes['start_time'])) {
            $attributes['start_time'] = substr($attributes['start_time'], 0, 5);
        }

        return $attributes;
    }

    private function nextReference(): string
    {
        $last = Installation::lockForUpdate()->pluck('reference')
            ->map(fn (string $ref) => preg_match('/(\d+)$/', $ref, $m) ? (int) $m[1] : 0)
            ->max() ?? 0;

        return self::PREFIX.str_pad((string) ($last + 1), 6, '0', STR_PAD_LEFT);
    }

    private function bottomOf(string $status): int
    {
        return (int) Installation::where('status', $status)->max('position') + 1;
    }

    private function compact(string $status): void
    {
        $ids = Installation::where('status', $status)->orderBy('position')->orderBy('id')->pluck('id');

        foreach ($ids as $position => $id) {
            Installation::whereKey($id)->update(['position' => $position]);
        }
    }

    private function fresh(Installation $job): Installation
    {
        return $job->fresh(['installer', 'order']);
    }
}
