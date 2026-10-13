<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Every peso actually received, from both places money comes in:
 *
 *  - `order`        — a paid order payment (hardware, plus any first subscription bundled into the order)
 *  - `subscription` — a paid subscription renewal invoice (settled online or marked paid by an admin)
 *
 * A renewal payment never creates a `payments` row, so the two never overlap.
 * Rows are `source`, `source_id`, `amount`, `paid_at`.
 */
class SalesLedger
{
    /**
     * Business days run on Philippine time. Timestamps are stored in UTC (the
     * app timezone), so "today" / "this month" are cut in Manila time and the
     * bounds converted back to UTC before querying.
     */
    public const TIMEZONE = 'Asia/Manila';

    /** Start and end of a Manila calendar day (default today), as Carbon in Manila time. */
    public static function day(?string $date = null): array
    {
        $start = $date ? Carbon::parse($date, self::TIMEZONE)->startOfDay() : Carbon::now(self::TIMEZONE)->startOfDay();

        return [$start, $start->copy()->endOfDay()];
    }

    public static function query(?Carbon $from = null, ?Carbon $to = null, ?string $search = null): Builder
    {
        $orders = DB::table('payments')
            ->selectRaw("'order' as source, id as source_id, amount, paid_at")
            ->where('status', 'paid')
            ->whereNotNull('paid_at');

        $renewals = DB::table('subscription_invoices')
            ->selectRaw("'subscription' as source, id as source_id, amount, paid_at")
            ->where('status', 'paid')
            ->whereNotNull('paid_at');

        // Bounds may be in any timezone; stored timestamps are UTC.
        $from = $from?->copy()->utc();
        $to = $to?->copy()->utc();
        foreach ([$orders, $renewals] as $q) {
            $q->when($from, fn ($q) => $q->where('paid_at', '>=', $from))
                ->when($to, fn ($q) => $q->where('paid_at', '<=', $to));
        }

        if ($search = trim((string) $search)) {
            $term = "%{$search}%";
            $orders->whereIn('order_id', DB::table('orders')->select('id')->where(fn ($o) => $o
                ->where('reference', 'like', $term)
                ->orWhere('customer_name', 'like', $term)
                ->orWhere('customer_email', 'like', $term)
                ->orWhere('customer_phone', 'like', $term)));

            $renewals->where(fn ($i) => $i
                ->where('reference', 'like', $term)
                ->orWhereIn('subscription_id', DB::table('subscriptions')->select('id')->where('reference', 'like', $term))
                ->orWhereIn('customer_id', DB::table('customers')->select('id')->where(fn ($c) => $c
                    ->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term))));
        }

        return DB::query()->fromSub($orders->unionAll($renewals), 'sales');
    }

    /** Total received between two moments (either end open). */
    public static function total(?Carbon $from = null, ?Carbon $to = null): int
    {
        return (int) static::query($from, $to)->sum('amount');
    }
}
