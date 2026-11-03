<?php

namespace App\Support;

use App\Models\Installation;
use App\Models\Installer;
use Illuminate\Support\Collection;

/**
 * Ranks active installers for a job site: free people first, then the shortest
 * distance, then the lightest day. Distance is measured from the installer's
 * home base, or from another job they already have that day if that is closer
 * — somebody finishing a job across the street is the natural pick.
 */
class InstallerSuggester
{
    public function suggest(
        ?float $lat,
        ?float $lng,
        ?string $date = null,
        ?string $startTime = null,
        int $durationMinutes = 120,
        ?int $ignore = null,
    ): array {
        $installers = Installer::where('is_active', true)->orderBy('name')->get();

        $dayJobs = $date === null ? collect() : Installation::whereIn('installer_id', $installers->pluck('id'))
            ->whereDate('scheduled_on', $date)
            ->where('status', '!=', 'cancelled')
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore))
            ->orderBy('start_time')
            ->get()
            ->groupBy('installer_id');

        $window = Installation::windowOf($startTime, $durationMinutes);

        $rows = $installers->map(fn (Installer $p) => $this->row($p, $dayJobs->get($p->id, collect()), $lat, $lng, $window));

        $sorted = $rows->sort(fn (array $a, array $b) => $this->key($a) <=> $this->key($b))->values();
        $best = $sorted->search(fn (array $r) => $r['available'] && $r['distance_km'] !== null);

        return $sorted->map(fn (array $r, int $i) => [...$r, 'recommended' => $i === $best])->all();
    }

    public static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dPhi = deg2rad($lat2 - $lat1);
        $dLambda = deg2rad($lng2 - $lng1);
        $a = sin($dPhi / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLambda / 2) ** 2;

        return 6371 * 2 * asin(min(1.0, sqrt($a)));
    }

    private function key(array $row): array
    {
        return [$row['available'] ? 0 : 1, $row['distance_km'] === null ? 1 : 0, $row['distance_km'] ?? 0, $row['jobs_that_day']];
    }

    /** @param Collection<int, Installation> $jobs */
    private function row(Installer $p, Collection $jobs, ?float $lat, ?float $lng, ?array $window): array
    {
        $baseKm = null;
        $nearest = null;
        $from = null;

        if ($lat !== null && $lng !== null) {
            if ($p->isPinned()) {
                $baseKm = self::km($p->base_lat, $p->base_lng, $lat, $lng);
                $nearest = $baseKm;
                $from = ['kind' => 'base', 'label' => $p->base_address ?: 'Home base'];
            }

            foreach ($jobs as $job) {
                if (! $job->isPinned()) continue;

                $km = self::km($job->lat, $job->lng, $lat, $lng);
                if ($nearest === null || $km < $nearest) {
                    $nearest = $km;
                    $from = ['kind' => 'job', 'label' => "{$job->reference} · {$job->address}"];
                }
            }
        }

        $clash = $window === null ? null : $jobs->first(function (Installation $job) use ($window) {
            $other = $job->window();

            return $other !== null && $other[0] < $window[1] && $window[0] < $other[1];
        });

        $load = $jobs->count();
        $full = $load >= max(1, $p->daily_capacity);
        $within = $baseKm === null ? null : $baseKm <= $p->service_radius_km;

        return [
            'installer_id' => $p->id,
            'name' => $p->name,
            'phone' => $p->phone,
            'skills' => $p->skills,
            'base_address' => $p->base_address,
            'base_lat' => $p->base_lat,
            'base_lng' => $p->base_lng,
            'distance_km' => $nearest === null ? null : round($nearest, 1),
            'base_distance_km' => $baseKm === null ? null : round($baseKm, 1),
            'from' => $from,
            'service_radius_km' => $p->service_radius_km,
            'within_radius' => $within,
            'jobs_that_day' => $load,
            'daily_capacity' => $p->daily_capacity,
            'full' => $full,
            'clash' => $clash ? ['reference' => $clash->reference, 'title' => $clash->title, 'start_time' => $clash->start_time] : null,
            'available' => $clash === null && ! $full,
            'reason' => $this->reason($p, $nearest, $from, $within, $clash, $full, $load),
        ];
    }

    private function reason(Installer $p, ?float $km, ?array $from, ?bool $within, ?Installation $clash, bool $full, int $load): string
    {
        if ($clash) return "Busy on {$clash->reference} from {$clash->start_time}.";
        if ($full) return "Fully booked — {$load} of {$p->daily_capacity} jobs that day.";
        if ($km === null) {
            return $p->isPinned() ? 'Pin the site on the map to measure the distance.' : 'No home base pinned, so the distance is unknown.';
        }

        $parts = [number_format($km, 1).' km '.($from['kind'] === 'job' ? "from their job at {$from['label']}" : 'from home base')];
        if ($within === false) $parts[] = "outside their {$p->service_radius_km} km area";
        $parts[] = $load === 0 ? 'free all day' : "{$load} of {$p->daily_capacity} jobs booked";

        return ucfirst(implode(' · ', $parts)).'.';
    }
}
