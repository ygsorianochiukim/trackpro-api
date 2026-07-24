<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class ReportsController extends Controller
{
    /**
     * GET /api/admin/reports/sales
     * Consolidated paid sales by day (14d), month (12mo), and year (5y).
     * Aggregated in PHP so it works on both SQLite and MySQL.
     */
    public function sales(): JsonResponse
    {
        $payments = Payment::where('status', 'paid')
            ->whereNotNull('paid_at')
            ->get(['amount', 'paid_at']);

        $byDay = [];
        $byMonth = [];
        $byYear = [];
        foreach ($payments as $p) {
            $c = $p->paid_at;
            $byDay[$c->format('Y-m-d')] = ($byDay[$c->format('Y-m-d')] ?? 0) + (int) $p->amount;
            $byMonth[$c->format('Y-m')] = ($byMonth[$c->format('Y-m')] ?? 0) + (int) $p->amount;
            $byYear[$c->format('Y')] = ($byYear[$c->format('Y')] ?? 0) + (int) $p->amount;
        }

        $daily = [];
        for ($i = 13; $i >= 0; $i--) {
            $d = Carbon::today()->subDays($i);
            $daily[] = ['label' => $d->format('M j'), 'total' => $byDay[$d->format('Y-m-d')] ?? 0];
        }

        $monthly = [];
        for ($i = 11; $i >= 0; $i--) {
            $d = Carbon::now()->startOfMonth()->subMonths($i);
            $monthly[] = ['label' => $d->format('M Y'), 'total' => $byMonth[$d->format('Y-m')] ?? 0];
        }

        $yearly = [];
        for ($i = 4; $i >= 0; $i--) {
            $y = Carbon::now()->year - $i;
            $yearly[] = ['label' => (string) $y, 'total' => $byYear[(string) $y] ?? 0];
        }

        $totals = [
            'today' => $byDay[Carbon::today()->format('Y-m-d')] ?? 0,
            'month' => $byMonth[Carbon::now()->format('Y-m')] ?? 0,
            'year' => $byYear[Carbon::now()->format('Y')] ?? 0,
            'all_time' => array_sum($byYear),
        ];

        return response()->json(compact('totals', 'daily', 'monthly', 'yearly'));
    }
}
