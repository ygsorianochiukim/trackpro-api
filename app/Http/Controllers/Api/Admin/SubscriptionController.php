<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    private const STATUSES = ['pending', 'active', 'past_due', 'expired', 'cancelled'];

    public function __construct(private SubscriptionService $subscriptions)
    {
    }

    /**
     * GET /api/admin/subscriptions
     * Filters: status, plan (id), due (soon|overdue), search (customer/reference).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Subscription::with(['customer:id,name,email,phone', 'plan:id,name'])
            ->withCount([
                'invoices as unpaid_invoices_count' => fn ($q) => $q->whereIn('status', ['unpaid', 'awaiting']),
            ]);

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        if ($planId = $request->integer('plan')) {
            $query->where('subscription_plan_id', $planId);
        }
        if ($due = $request->string('due')->toString()) {
            match ($due) {
                'soon' => $query->whereBetween('renews_at', [
                    Carbon::today()->toDateString(),
                    Carbon::today()->addDays(SubscriptionInvoice::BILL_AHEAD_DAYS)->toDateString(),
                ]),
                'overdue' => $query->where('renews_at', '<', Carbon::today()->toDateString())
                    ->whereNotIn('status', ['cancelled']),
                default => null,
            };
        }
        if ($search = trim($request->string('search')->toString())) {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('plan_name', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"));
            });
        }

        $page = $query->orderBy('renews_at')->paginate(15)->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (Subscription $s) => $this->payload($s))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'has_more' => $page->hasMorePages(),
            ],
            'stats' => $this->stats(),
            'plans' => SubscriptionPlan::orderBy('price')->get(['id', 'slug', 'name', 'price', 'billing_period', 'is_active']),
        ]);
    }

    /** GET /api/admin/subscriptions/{subscription} */
    public function show(Subscription $subscription): JsonResponse
    {
        $subscription->load(['customer:id,name,email,phone', 'plan:id,name', 'invoices', 'order:id,reference']);

        return response()->json(['data' => $this->payload($subscription, withInvoices: true)]);
    }

    /**
     * POST /api/admin/subscriptions — create one by hand (walk-in customer,
     * migrated subscriber, or a device sold offline).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'subscription_plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'plan_name' => ['nullable', 'string', 'max:120'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'unit_price' => ['required', 'integer', 'min:0'],
            'billing_period' => ['nullable', Rule::in(['yearly', 'monthly'])],
            'starts_at' => ['required', 'date'],
            'renews_at' => ['nullable', 'date', 'after:starts_at'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $plan = isset($data['subscription_plan_id'])
            ? SubscriptionPlan::find($data['subscription_plan_id'])
            : null;
        $period = $data['billing_period'] ?? $plan?->billing_period ?? 'yearly';
        $starts = Carbon::parse($data['starts_at'])->startOfDay();
        $renews = isset($data['renews_at'])
            ? Carbon::parse($data['renews_at'])
            : ($period === 'monthly' ? $starts->copy()->addMonth() : $starts->copy()->addYear());

        $subscription = Subscription::create([
            'reference' => Subscription::nextReference(),
            'customer_id' => $data['customer_id'],
            'subscription_plan_id' => $plan?->id,
            'plan_name' => ($data['plan_name'] ?? null) ?: ($plan?->name ?? 'TrackPro GPS Tracking (Yearly)'),
            'billing_period' => $period,
            'quantity' => $data['quantity'],
            'unit_price' => $data['unit_price'],
            'price' => $data['unit_price'] * $data['quantity'],
            'status' => $data['status'] ?? 'active',
            'starts_at' => $starts->toDateString(),
            'renews_at' => $renews->toDateString(),
            'auto_renew' => true,
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json(['data' => $this->payload($subscription->fresh(['customer', 'plan']))], 201);
    }

    /** PUT|PATCH /api/admin/subscriptions/{subscription} */
    public function update(Request $request, Subscription $subscription): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'unit_price' => ['nullable', 'integer', 'min:0'],
            'renews_at' => ['nullable', 'date'],
            'auto_renew' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($request->filled('status') && $data['status'] === 'cancelled') {
            return response()->json(['data' => $this->payload($this->subscriptions->cancel($subscription))]);
        }

        $update = array_filter(
            $data,
            fn ($v, $k) => $v !== null || $k === 'notes',
            ARRAY_FILTER_USE_BOTH
        );

        // Quantity or unit price changed — recompute the period total.
        $quantity = $update['quantity'] ?? $subscription->quantity;
        $unitPrice = $update['unit_price'] ?? $subscription->unit_price;
        if (isset($update['quantity']) || isset($update['unit_price'])) {
            $update['price'] = $quantity * $unitPrice;
        }

        $subscription->update($update);

        return response()->json(['data' => $this->payload($subscription->fresh(['customer', 'plan']))]);
    }

    /**
     * POST /api/admin/subscriptions/{subscription}/invoice
     * Raise the next renewal invoice now (e.g. to send it early).
     */
    public function generateInvoice(Subscription $subscription): JsonResponse
    {
        $invoice = $this->subscriptions->ensureDueInvoice($subscription, force: true);

        if (!$invoice) {
            return response()->json(['message' => 'Nothing to invoice for this subscription.'], 422);
        }

        return response()->json(['data' => $invoice->fresh()]);
    }

    /** Aggregate figures for the admin Subscriptions header. */
    private function stats(): array
    {
        $today = Carbon::today();

        return [
            'total' => Subscription::count(),
            'active' => Subscription::where('status', 'active')->count(),
            'past_due' => Subscription::where('status', 'past_due')->count(),
            'expired' => Subscription::where('status', 'expired')->count(),
            'cancelled' => Subscription::where('status', 'cancelled')->count(),
            'devices_covered' => (int) Subscription::whereIn('status', ['active', 'past_due'])->sum('quantity'),
            // Annual recurring revenue — monthly plans normalised to a year.
            'arr' => (int) Subscription::where('status', 'active')->where('billing_period', 'yearly')->sum('price')
                + (int) Subscription::where('status', 'active')->where('billing_period', 'monthly')->sum('price') * 12,
            'renewals_due_soon' => Subscription::whereBetween('renews_at', [
                $today->toDateString(),
                $today->copy()->addDays(SubscriptionInvoice::BILL_AHEAD_DAYS)->toDateString(),
            ])->whereNotIn('status', ['cancelled', 'expired'])->count(),
            'outstanding' => (int) SubscriptionInvoice::whereIn('status', ['unpaid', 'awaiting'])->sum('amount'),
            'collected_this_year' => (int) SubscriptionInvoice::where('status', 'paid')
                ->whereYear('paid_at', $today->year)
                ->sum('amount'),
        ];
    }

    private function payload(Subscription $s, bool $withInvoices = false): array
    {
        $payload = [
            'id' => $s->id,
            'reference' => $s->reference,
            'customer' => $s->customer
                ? ['id' => $s->customer->id, 'name' => $s->customer->name, 'email' => $s->customer->email, 'phone' => $s->customer->phone]
                : null,
            'plan_name' => $s->plan_name,
            'billing_period' => $s->billing_period,
            'quantity' => $s->quantity,
            'unit_price' => $s->unit_price,
            'price' => $s->price,
            'status' => $s->status,
            'starts_at' => $s->starts_at?->toDateString(),
            'renews_at' => $s->renews_at?->toDateString(),
            'days_until_renewal' => $s->days_until_renewal,
            'auto_renew' => $s->auto_renew,
            'last_paid_at' => $s->last_paid_at?->toIso8601String(),
            'unpaid_invoices_count' => (int) ($s->unpaid_invoices_count ?? 0),
            'order_reference' => $s->order?->reference,
            'notes' => $s->notes,
        ];

        if ($withInvoices) {
            $payload['invoices'] = $s->invoices;
        }

        return $payload;
    }

    /** GET /api/admin/customers — lightweight picker for the create form. */
    public function customers(Request $request): JsonResponse
    {
        $query = Customer::query();

        if ($search = trim($request->string('search')->toString())) {
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%"));
        }

        $customers = $query->orderBy('name')->limit(50)->get(['id', 'name', 'email', 'phone']);

        return response()->json(['data' => $customers]);
    }
}
