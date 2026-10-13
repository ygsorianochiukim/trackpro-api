<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\SubscriptionInvoice;
use App\Support\SalesLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ReportsController extends Controller
{
    /**
     * GET /api/admin/reports/sales
     * Consolidated paid sales — order payments and subscription renewals — by
     * day (14d), month (12mo), and year (5y).
     * Aggregated in PHP so it works on both SQLite and MySQL; days, months and
     * years are Philippine time (see SalesLedger::TIMEZONE).
     */
    public function sales(): JsonResponse
    {
        $tz = SalesLedger::TIMEZONE;
        $now = Carbon::now($tz);
        $payments = SalesLedger::query()->get(['amount', 'paid_at']);

        $byDay = [];
        $byMonth = [];
        $byYear = [];
        foreach ($payments as $p) {
            $c = Carbon::parse($p->paid_at, 'UTC')->tz($tz);
            $byDay[$c->format('Y-m-d')] = ($byDay[$c->format('Y-m-d')] ?? 0) + (int) $p->amount;
            $byMonth[$c->format('Y-m')] = ($byMonth[$c->format('Y-m')] ?? 0) + (int) $p->amount;
            $byYear[$c->format('Y')] = ($byYear[$c->format('Y')] ?? 0) + (int) $p->amount;
        }

        $daily = [];
        for ($i = 13; $i >= 0; $i--) {
            $d = $now->copy()->startOfDay()->subDays($i);
            $daily[] = ['label' => $d->format('M j'), 'total' => $byDay[$d->format('Y-m-d')] ?? 0];
        }

        $monthly = [];
        for ($i = 11; $i >= 0; $i--) {
            $d = $now->copy()->startOfMonth()->subMonths($i);
            $monthly[] = ['label' => $d->format('M Y'), 'total' => $byMonth[$d->format('Y-m')] ?? 0];
        }

        $yearly = [];
        for ($i = 4; $i >= 0; $i--) {
            $y = $now->year - $i;
            $yearly[] = ['label' => (string) $y, 'total' => $byYear[(string) $y] ?? 0];
        }

        $totals = [
            'today' => $byDay[$now->format('Y-m-d')] ?? 0,
            'month' => $byMonth[$now->format('Y-m')] ?? 0,
            'year' => $byYear[$now->format('Y')] ?? 0,
            'all_time' => array_sum($byYear),
        ];

        return response()->json(compact('totals', 'daily', 'monthly', 'yearly'));
    }

    /**
     * GET /api/admin/reports/transactions?from=&to=&q=&page=
     * Paid sales, newest first: who bought (customer details), what they bought
     * (order items, or the subscription renewed) and how much was paid.
     * `totals.amount` covers the whole filter.
     */
    public function transactions(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $query = SalesLedger::query(
            $request->filled('from') ? SalesLedger::day($request->from)[0] : null,
            $request->filled('to') ? SalesLedger::day($request->to)[1] : null,
            $request->q,
        );

        $total = (int) (clone $query)->sum('amount');
        $page = $query->orderByDesc('paid_at')->orderByDesc('source_id')->paginate(15)->withQueryString();

        return response()->json([
            'data' => $this->rows(collect($page->items())),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'has_more' => $page->hasMorePages(),
            ],
            'totals' => ['amount' => $total, 'count' => $page->total()],
        ]);
    }

    /**
     * GET /api/admin/reports/today?date=YYYY-MM-DD
     * The sales log for one Philippine-time day (default today): every sale,
     * newest first — when, who bought, what they bought, how much and how paid.
     */
    public function today(Request $request): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        [$start, $end] = SalesLedger::day($request->date);

        $entries = SalesLedger::query($start, $end)
            ->orderByDesc('paid_at')->orderByDesc('source_id')
            ->limit(500)
            ->get();

        return response()->json([
            'date' => $start->toDateString(),
            'data' => $this->rows($entries),
            'totals' => [
                'amount' => (int) $entries->sum('amount'),
                'count' => $entries->count(),
                'orders' => $entries->where('source', 'order')->count(),
                'renewals' => $entries->where('source', 'subscription')->count(),
            ],
        ]);
    }

    /** Ledger entries → report rows, hydrated from their own tables. */
    private function rows(Collection $entries): Collection
    {
        $payments = Payment::with(['order.items', 'order.customer'])
            ->findMany($entries->where('source', 'order')->pluck('source_id'))
            ->keyBy('id');
        $renewals = SubscriptionInvoice::with(['customer', 'subscription'])
            ->findMany($entries->where('source', 'subscription')->pluck('source_id'))
            ->keyBy('id');

        return $entries->map(fn ($e) => $e->source === 'order'
            ? $this->orderRow($payments[$e->source_id])
            : $this->renewalRow($renewals[$e->source_id]))->values();
    }

    private function orderRow(Payment $p): array
    {
        return [
            'type' => 'order',
            'id' => $p->id,
            'paid_at' => $p->paid_at,
            'amount' => $p->amount,
            'method' => $p->payment_method ?? $p->provider,
            'order' => [
                'id' => $p->order->id,
                'reference' => $p->order->reference,
                'status' => $p->order->status,
                'hardware_subtotal' => $p->order->hardware_subtotal,
                'subscription_total' => $p->order->subscription_total,
                'has_invoice' => $p->order->invoice !== null,
                'items' => $p->order->items->map(fn ($i) => [
                    'name' => $i->product_name,
                    'model' => $i->product_model,
                    'qty' => $i->qty,
                    'unit_price' => $i->unit_price,
                    'line_total' => $i->line_total,
                ]),
            ],
            'renewal' => null,
            'customer' => [
                'id' => $p->order->customer_id,
                'name' => $p->order->customer_name,
                'email' => $p->order->customer_email,
                'phone' => $p->order->customer_phone,
                'company' => $p->order->customer?->company,
                'address' => $p->order->delivery_address,
            ],
        ];
    }

    private function renewalRow(SubscriptionInvoice $i): array
    {
        return [
            'type' => 'subscription',
            'id' => $i->id,
            'paid_at' => $i->paid_at,
            'amount' => $i->amount,
            'method' => $i->payment_method ?? $i->provider,
            'order' => null,
            'renewal' => [
                'id' => $i->id,
                'reference' => $i->reference,
                'description' => $i->description,
                'plan_name' => $i->subscription?->plan_name,
                'subscription_reference' => $i->subscription?->reference,
                'quantity' => $i->subscription?->quantity,
                'period_start' => $i->period_start?->toDateString(),
                'period_end' => $i->period_end?->toDateString(),
            ],
            'customer' => [
                'id' => $i->customer_id,
                'name' => $i->customer?->name ?? '—',
                'email' => $i->customer?->email,
                'phone' => $i->customer?->phone,
                'company' => $i->customer?->company,
                'address' => null,
            ],
        ];
    }
}
