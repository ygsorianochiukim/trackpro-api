<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

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
            ->withSum(['orders as total_spent' => fn ($q) => $q->where('payment_status', 'paid')], 'total')
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

    /**
     * POST /api/admin/clients — create a client account by hand (walk-ins,
     * phone orders, clients migrated from paper records).
     *
     * The admin vouches for the email, so it is marked verified. If no password
     * is given a random one is set; with `send_invite` the client is emailed a
     * password-reset link so they can choose their own and sign in.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:120', 'unique:customers,email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'company' => ['nullable', 'string', 'max:160'],
            'fleet_size' => ['nullable', 'string', 'max:32'],
            'vehicle_info' => ['nullable', 'string', 'max:2000'],
            'password' => ['nullable', 'string', 'min:6'],
            'send_invite' => ['boolean'],
        ]);

        $sendInvite = (bool) ($data['send_invite'] ?? false);
        unset($data['send_invite']);
        $data['password'] = $data['password'] ?? Str::random(32);

        $customer = Customer::create($data);
        $customer->markEmailAsVerified();

        if ($sendInvite) {
            Password::broker('customers')->sendResetLink(['email' => $customer->email]);
        }

        return response()->json([
            'data' => $this->summary($customer),
            'invite_sent' => $sendInvite,
        ], 201);
    }

    /**
     * PUT /api/admin/clients/{customer} — edit a client's profile. A new
     * password is optional; leaving it blank keeps the current one.
     */
    public function update(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:120', Rule::unique('customers', 'email')->ignore($customer->id)],
            'phone' => ['nullable', 'string', 'max:32'],
            'company' => ['nullable', 'string', 'max:160'],
            'fleet_size' => ['nullable', 'string', 'max:32'],
            'vehicle_info' => ['nullable', 'string', 'max:2000'],
            'password' => ['nullable', 'string', 'min:6'],
        ]);

        if (empty($data['password'])) {
            unset($data['password']);
        }

        $customer->update($data);

        return response()->json(['data' => $this->summary($customer)]);
    }

    /**
     * DELETE /api/admin/clients/{customer}
     *
     * Refused while the client has an active plan or paid invoices — those are
     * financial records. Otherwise their unpaid invoices and inactive
     * subscriptions go with them, and their orders are kept (each order holds
     * its own snapshot of the contact details) but unlinked from the account.
     */
    public function destroy(Customer $customer): JsonResponse
    {
        if ($customer->subscriptions()->where('status', 'active')->exists()) {
            return response()->json([
                'message' => 'This client has an active subscription — cancel it before deleting the client.',
            ], 422);
        }

        if ($customer->subscriptionInvoices()->where('status', 'paid')->exists()) {
            return response()->json([
                'message' => 'This client has paid invoices, so they cannot be deleted.',
            ], 422);
        }

        DB::transaction(function () use ($customer) {
            $customer->orders()->update(['customer_id' => null]);
            $customer->subscriptionInvoices()->delete();
            $customer->subscriptions()->delete();
            $customer->tokens()->delete();
            DB::table('customer_password_reset_tokens')->where('email', $customer->email)->delete();
            $customer->delete();
        });

        return response()->json(['message' => "{$customer->name} deleted."]);
    }

    /** GET /api/admin/clients/{customer} — profile + orders + subscriptions + billing. */
    public function show(Customer $customer): JsonResponse
    {
        $orders = $customer->orders()
            ->withCount('items')
            ->latest()
            ->get(['id', 'reference', 'customer_id', 'hardware_subtotal', 'subscription_total', 'total', 'status', 'payment_status', 'paid_at', 'created_at']);

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
                    'hardware_paid' => (int) $orders->where('payment_status', 'paid')->sum('total'),
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
