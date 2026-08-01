<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    /** GET /api/admin/dashboard */
    public function index(): JsonResponse
    {
        $stats = [
            'orders_total' => Order::count(),
            'orders_pending' => Order::where('status', 'pending')->count(),
            'orders_paid' => Order::where('payment_status', 'paid')->count(),
            'revenue_total' => (int) Payment::where('status', 'paid')->sum('amount'),
            'revenue_today' => (int) Payment::where('status', 'paid')->whereDate('paid_at', today())->sum('amount'),
            'products_total' => Product::count(),
            'products_out_of_stock' => Product::where('stock', '<=', 0)->count(),
            'products_low_stock' => Product::whereBetween('stock', [1, 5])->count(),
        ];

        $recentOrders = Order::withCount('items')->latest()->limit(8)->get()
            ->map(fn (Order $o) => [
                'id' => $o->id,
                'reference' => $o->reference,
                'customer_name' => $o->customer_name,
                'items_count' => $o->items_count,
                'hardware_subtotal' => $o->hardware_subtotal,
                'payment_status' => $o->payment_status,
                'created_at' => $o->created_at,
            ]);

        $lowStock = Product::where('stock', '<=', 5)
            ->orderBy('stock')
            ->limit(5)
            ->get(['id', 'model', 'name', 'stock']);

        return response()->json([
            'stats' => $stats,
            'recent_orders' => $recentOrders,
            'low_stock' => $lowStock,
            'subscriptions' => $this->subscriptionSummary(),
        ]);
    }

    /** Yearly-subscription snapshot for the dashboard card. */
    private function subscriptionSummary(): array
    {
        $today = Carbon::today();

        return [
            'active' => Subscription::where('status', 'active')->count(),
            'past_due' => Subscription::where('status', 'past_due')->count(),
            'arr' => (int) Subscription::where('status', 'active')->where('billing_period', 'yearly')->sum('price')
                + (int) Subscription::where('status', 'active')->where('billing_period', 'monthly')->sum('price') * 12,
            'renewals_due_soon' => Subscription::whereBetween('renews_at', [
                $today->toDateString(),
                $today->copy()->addDays(SubscriptionInvoice::BILL_AHEAD_DAYS)->toDateString(),
            ])->whereNotIn('status', ['cancelled', 'expired'])->count(),
            'outstanding' => (int) SubscriptionInvoice::whereIn('status', ['unpaid', 'awaiting'])->sum('amount'),
        ];
    }
}
