<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin → Clients: one place for a customer's profile, orders, subscription
 * plans and billing.
 */
class ClientController extends Controller
{
    /** GET /api/admin/clients — searchable, paginated client directory. */
    public function index(Request $request): JsonResponse
    {
        $clients = Customer::query()
            ->when(trim($request->string('search')->toString()), function ($q, $search) {
                $q->where(fn ($w) => $w
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('company', 'like', "%{$search}%"));
            })
            ->withCount([
                'orders',
                'subscriptions as active_subscriptions_count' => fn ($q) => $q->where('status', 'active'),
                'subscriptionInvoices as unpaid_invoices_count' => fn ($q) => $q->whereIn('status', ['unpaid', 'awaiting']),
            ])
            ->withSum(['orders as total_spent' => fn ($q) => $q->where('payment_status', 'paid')], 'hardware_subtotal')
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return response()->json([
            'data' => collect($clients->items())->map(fn (Customer $c) => $this->summary($c) + [
                'orders_count' => (int) $c->orders_count,
                'active_subscriptions_count' => (int) $c->active_subscriptions_count,
                'unpaid_invoices_count' => (int) $c->unpaid_invoices_count,
                'total_spent' => (int) $c->total_spent,
            ]),
            'meta' => [
                'current_page' => $clients->currentPage(),
                'last_page' => $clients->lastPage(),
                'total' => $clients->total(),
                'has_more' => $clients->hasMorePages(),
            ],
            'totals' => [
                'clients' => Customer::count(),
                'with_active_plan' => Customer::whereHas('subscriptions', fn ($q) => $q->where('status', 'active'))->count(),
                'new_this_month' => Customer::where('created_at', '>=', now()->startOfMonth())->count(),
            ],
        ]);
    }

    /** GET /api/admin/clients/{customer} — profile + orders + subscriptions + billing. */
    public function show(Customer $customer): JsonResponse
    {
        $orders = $customer->orders()
            ->withCount('items')
            ->latest()
            ->get(['id', 'reference', 'customer_id', 'hardware_subtotal', 'subscription_total', 'status', 'payment_status', 'paid_at', 'created_at']);

        $subscriptions = $customer->subscriptions()
            ->latest()
            ->get(['id', 'reference', 'customer_id', 'plan_name', 'billing_period', 'quantity', 'unit_price', 'price', 'status', 'starts_at', 'renews_at', 'auto_renew', 'last_paid_at']);

        $invoices = $customer->subscriptionInvoices()
            ->with('subscription:id,reference')
            ->latest()
            ->get(['id', 'reference', 'subscription_id', 'customer_id', 'description', 'amount', 'period_start', 'period_end', 'due_date', 'status', 'payment_method', 'paid_at', 'created_at']);

        return response()->json([
            'data' => $this->summary($customer) + [
                'vehicle_info' => $customer->vehicle_info,
                'terms_accepted_at' => $customer->terms_accepted_at,
                'orders' => $orders,
                'subscriptions' => $subscriptions,
                'invoices' => $invoices,
                'totals' => [
                    'hardware_paid' => (int) $orders->where('payment_status', 'paid')->sum('hardware_subtotal'),
                    'billing_paid' => (int) $invoices->where('status', 'paid')->sum('amount'),
                    'billing_outstanding' => (int) $invoices->whereIn('status', ['unpaid', 'awaiting'])->sum('amount'),
                ],
            ],
        ]);
    }

    private function summary(Customer $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'email' => $c->email,
            'phone' => $c->phone,
            'company' => $c->company,
            'fleet_size' => $c->fleet_size,
            'email_verified' => $c->hasVerifiedEmail(),
            'created_at' => $c->created_at,
        ];
    }
}
