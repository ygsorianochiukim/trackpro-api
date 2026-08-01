<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Services\PayMongoService;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The signed-in customer's own area: dashboard, yearly subscriptions, and
 * billing. Every query is scoped to `$request->user()` — a customer can only
 * ever see and pay for their own records.
 */
class CustomerAccountController extends Controller
{
    public function __construct(
        private SubscriptionService $subscriptions,
        private PayMongoService $paymongo,
    ) {
    }

    /** GET /api/customer/dashboard — everything the account overview shows. */
    public function dashboard(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $subscriptions = $this->syncedSubscriptions($customer);

        $openInvoices = SubscriptionInvoice::where('customer_id', $customer->id)
            ->whereIn('status', ['unpaid', 'awaiting'])
            ->orderBy('due_date')
            ->get();

        // The subscription the overview leads with: the soonest renewal among
        // the live ones, else simply the newest.
        $primary = $subscriptions->whereIn('status', ['active', 'past_due', 'pending'])
            ->sortBy(fn (Subscription $s) => $s->renews_at->timestamp)
            ->first() ?? $subscriptions->first();

        $recentOrders = $customer->orders()
            ->latest()
            ->limit(5)
            ->get(['id', 'reference', 'hardware_subtotal', 'status', 'payment_status', 'created_at']);

        return response()->json([
            'customer' => $this->customerPayload($customer),
            'stats' => [
                'active_subscriptions' => $subscriptions->where('status', 'active')->count(),
                'devices_covered' => (int) $subscriptions
                    ->whereIn('status', ['active', 'past_due'])
                    ->sum('quantity'),
                'next_renewal_date' => $primary?->renews_at?->toDateString(),
                'days_until_renewal' => $primary?->days_until_renewal,
                'amount_due' => (int) $openInvoices->sum('amount'),
                'unpaid_invoices' => $openInvoices->count(),
                'orders_total' => $customer->orders()->count(),
            ],
            'subscription' => $primary ? $this->subscriptionPayload($primary) : null,
            'subscriptions' => $subscriptions->map(fn ($s) => $this->subscriptionPayload($s))->values(),
            'open_invoices' => $openInvoices->map(fn ($i) => $this->invoicePayload($i))->values(),
            'recent_orders' => $recentOrders,
        ]);
    }

    /** GET /api/customer/subscriptions */
    public function subscriptions(Request $request): JsonResponse
    {
        $subscriptions = $this->syncedSubscriptions($this->customer($request));

        return response()->json([
            'data' => $subscriptions->map(fn ($s) => $this->subscriptionPayload($s, withInvoices: true))->values(),
        ]);
    }

    /** GET /api/customer/subscriptions/{reference} */
    public function showSubscription(Request $request, string $reference): JsonResponse
    {
        $subscription = $this->findSubscription($request, $reference);
        $this->subscriptions->refreshStatus($subscription);
        $this->subscriptions->ensureDueInvoice($subscription);

        return response()->json([
            'data' => $this->subscriptionPayload($subscription->fresh(['invoices']), withInvoices: true),
        ]);
    }

    /**
     * POST /api/customer/subscriptions — sign up for a published yearly plan.
     * Creates the subscription in `pending` plus an invoice for period one.
     */
    public function subscribe(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        $data = $request->validate([
            'plan_slug' => ['required', 'string', 'exists:subscription_plans,slug'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $plan = SubscriptionPlan::where('slug', $data['plan_slug'])
            ->where('is_active', true)
            ->firstOrFail();

        $subscription = $this->subscriptions->subscribeToPlan($customer, $plan, (int) ($data['quantity'] ?? 1));
        $invoice = $subscription->openInvoice();
        if ($invoice) {
            $invoice = $this->subscriptions->attachCheckout($invoice);
        }

        return response()->json([
            'data' => $this->subscriptionPayload($subscription, withInvoices: true),
            'invoice' => $invoice ? $this->invoicePayload($invoice) : null,
            'checkout_url' => $invoice?->checkout_url,
        ], 201);
    }

    /**
     * POST /api/customer/subscriptions/{reference}/renew
     *
     * Raises (or returns) the invoice for the next yearly period and attaches a
     * PayMongo checkout link when the gateway is configured. Safe to call twice
     * — the open invoice is reused rather than duplicated.
     */
    public function renew(Request $request, string $reference): JsonResponse
    {
        $subscription = $this->findSubscription($request, $reference);

        if ($subscription->status === 'cancelled') {
            return response()->json(['message' => 'This subscription was cancelled. Contact support to reactivate it.'], 422);
        }

        $invoice = $this->subscriptions->ensureDueInvoice($subscription, force: true);
        if (!$invoice) {
            return response()->json(['message' => 'Nothing to renew right now.'], 422);
        }

        $invoice = $this->subscriptions->attachCheckout($invoice);

        return response()->json([
            'invoice' => $this->invoicePayload($invoice),
            'checkout_url' => $invoice->checkout_url,
        ]);
    }

    /** POST /api/customer/subscriptions/{reference}/cancel — stops auto-renewal. */
    public function cancelSubscription(Request $request, string $reference): JsonResponse
    {
        $subscription = $this->subscriptions->cancel($this->findSubscription($request, $reference));

        return response()->json(['data' => $this->subscriptionPayload($subscription)]);
    }

    /** GET /api/customer/invoices — billing history. */
    public function invoices(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        // Raise any invoice that has come due, so Billing never looks empty when
        // a renewal is actually imminent.
        $this->syncedSubscriptions($customer);

        $invoices = SubscriptionInvoice::with('subscription:id,reference,plan_name')
            ->where('customer_id', $customer->id)
            ->orderByDesc('period_start')
            ->get();

        return response()->json([
            'data' => $invoices->map(fn ($i) => $this->invoicePayload($i))->values(),
            'totals' => [
                'outstanding' => (int) $invoices->whereIn('status', ['unpaid', 'awaiting'])->sum('amount'),
                'paid_all_time' => (int) $invoices->where('status', 'paid')->sum('amount'),
            ],
        ]);
    }

    /**
     * POST /api/customer/invoices/{reference}/pay
     *
     * With PayMongo configured this returns a hosted checkout URL and the
     * invoice is only settled by the webhook.
     *
     * ⚠️  Without PayMongo it falls back to the same TEMPORARY stand-in the
     * order flow uses — it marks the invoice paid on the customer's word.
     * Remove that branch before taking real money (see DEPLOY.md).
     */
    public function payInvoice(Request $request, string $reference): JsonResponse
    {
        $invoice = SubscriptionInvoice::where('reference', $reference)->firstOrFail();

        // Own-invoices only: 404 (not 403) so references aren't enumerable.
        abort_unless($invoice->customer_id === $request->user()->id, 404);

        // Never send someone to checkout twice: if they already paid at the
        // gateway and we simply hadn't heard, settle it now.
        $invoice = $this->subscriptions->reconcile($invoice);

        if ($invoice->status === 'paid') {
            // Not an error from the customer's point of view — they paid.
            return response()->json([
                'message' => 'This invoice is already paid — thank you! Your subscription has been renewed.',
                'invoice' => $this->invoicePayload($invoice),
                'subscription' => $this->subscriptionPayload($invoice->subscription),
            ]);
        }

        if (!$invoice->isPayable()) {
            return response()->json([
                'message' => 'This invoice is no longer payable.',
                'invoice' => $this->invoicePayload($invoice),
            ], 422);
        }

        if ($this->paymongo->isConfigured()) {
            $invoice = $this->subscriptions->attachCheckout($invoice);

            return response()->json([
                'invoice' => $this->invoicePayload($invoice),
                'checkout_url' => $invoice->checkout_url,
                'message' => 'Continue to the secure checkout to complete your payment.',
            ]);
        }

        $invoice = $this->subscriptions->markInvoicePaid($invoice, 'temporary', 'temporary');

        return response()->json([
            'invoice' => $this->invoicePayload($invoice),
            'subscription' => $this->subscriptionPayload($invoice->subscription),
            'message' => 'Payment recorded. Your subscription has been renewed.',
        ]);
    }

    /* ---------------------------------------------------------------- helpers */

    private function customer(Request $request): Customer
    {
        $customer = $request->user();
        abort_unless($customer instanceof Customer, 403, 'A customer account is required.');

        return $customer;
    }

    /** Load the customer's subscriptions with statuses and due invoices up to date. */
    private function syncedSubscriptions(Customer $customer): Collection
    {
        // Settle anything already paid at the gateway before deriving statuses,
        // otherwise a paid invoice would still read as awaiting. See
        // SubscriptionService::reconcile — this is what covers a missing webhook.
        $this->subscriptions->reconcileAll(
            SubscriptionInvoice::where('customer_id', $customer->id)
                ->where('status', 'awaiting')
                ->whereNotNull('provider_link_id')
                ->get()
        );

        return $customer->subscriptions()
            ->with(['invoices', 'plan:id,slug,name'])
            ->latest('id')
            ->get()
            ->map(function (Subscription $s) {
                $this->subscriptions->refreshStatus($s);
                $this->subscriptions->ensureDueInvoice($s);

                return $s->fresh(['invoices', 'plan']);
            });
    }

    private function findSubscription(Request $request, string $reference): Subscription
    {
        $subscription = Subscription::with('invoices')->where('reference', $reference)->firstOrFail();
        abort_unless($subscription->customer_id === $request->user()->id, 404);

        return $subscription;
    }

    private function customerPayload(Customer $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'email' => $c->email,
            'phone' => $c->phone,
            'email_verified' => $c->hasVerifiedEmail(),
            'member_since' => $c->created_at?->toDateString(),
        ];
    }

    private function subscriptionPayload(Subscription $s, bool $withInvoices = false): array
    {
        $open = $s->openInvoice();

        $payload = [
            'reference' => $s->reference,
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
            'notes' => $s->notes,
            'open_invoice' => $open ? $this->invoicePayload($open) : null,
        ];

        if ($withInvoices) {
            $payload['invoices'] = $s->invoices->map(fn ($i) => $this->invoicePayload($i))->values();
        }

        return $payload;
    }

    private function invoicePayload(SubscriptionInvoice $i): array
    {
        return [
            'reference' => $i->reference,
            'subscription_reference' => $i->subscription?->reference,
            'description' => $i->description,
            'amount' => $i->amount,
            'period_start' => $i->period_start?->toDateString(),
            'period_end' => $i->period_end?->toDateString(),
            'due_date' => $i->due_date?->toDateString(),
            'status' => $i->status,
            'checkout_url' => $i->checkout_url,
            'payment_method' => $i->payment_method,
            'paid_at' => $i->paid_at?->toIso8601String(),
            // Due *today* is not yet overdue — compare dates, not timestamps.
            'is_overdue' => $i->isPayable() && $i->due_date?->lt(Carbon::today()),
        ];
    }
}
