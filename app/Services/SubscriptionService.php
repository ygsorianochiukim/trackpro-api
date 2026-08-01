<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Yearly tracking-subscription lifecycle.
 *
 * A subscription is created either by buying hardware (`provisionForOrder`, run
 * whenever an order becomes paid) or by signing up to a plan from the account
 * area (`subscribeToPlan`). Every period the customer must pay for gets a
 * `SubscriptionInvoice` — that is what the customer's Billing page lists and
 * what "Renew" pays.
 *
 * Invoice creation is idempotent: the `(subscription_id, period_start)` unique
 * index plus the open-invoice lookup mean repeated renew clicks, the nightly
 * `subscriptions:bill` command, and page loads can never double-bill.
 */
class SubscriptionService
{
    public function __construct(private PayMongoService $paymongo)
    {
    }

    /**
     * Provision a yearly subscription for a freshly-paid order. Idempotent —
     * calling it twice on the same order returns the existing subscription.
     *
     * Returns null when the order carries no tracking fee and no plan is
     * configured, i.e. there is nothing to renew.
     */
    public function provisionForOrder(Order $order): ?Subscription
    {
        if ($existing = $order->subscriptions()->first()) {
            return $existing;
        }
        if (!$order->customer_id) {
            return null; // guest order — nothing to attach a subscription to
        }

        $order->loadMissing('items');
        $quantity = max(1, (int) $order->items->sum('qty'));

        // The catalog stores a MONTHLY fee per device; subscriptions bill yearly.
        // Only fall back to the default plan's price when the ordered products
        // carry no tracking fee — otherwise the order's own pricing wins, and
        // labelling it with a plan whose price differs would be misleading.
        $yearlyTotal = (int) $order->subscription_total * 12;
        $plan = null;

        if ($yearlyTotal <= 0) {
            $plan = SubscriptionPlan::default();
            if (!$plan) {
                return null;
            }
            $yearlyTotal = $plan->price * $quantity;
        }
        if ($yearlyTotal <= 0) {
            return null;
        }

        $start = ($order->paid_at ?? now())->copy()->startOfDay();

        // First year bundled with the hardware purchase by default — checkout only
        // ever charged `hardware_subtotal`. Set SUBSCRIPTION_FIRST_YEAR_INCLUDED=false
        // to bill the customer for year one instead.
        $firstYearIncluded = (bool) env('SUBSCRIPTION_FIRST_YEAR_INCLUDED', true);

        $subscription = Subscription::create([
            'reference' => Subscription::nextReference(),
            'customer_id' => $order->customer_id,
            'subscription_plan_id' => $plan?->id,
            'order_id' => $order->id,
            'plan_name' => $plan?->name ?? 'TrackPro GPS Tracking (Yearly)',
            'billing_period' => 'yearly',
            'quantity' => $quantity,
            'unit_price' => intdiv($yearlyTotal, $quantity),
            'price' => $yearlyTotal,
            'status' => $firstYearIncluded ? 'active' : 'pending',
            'starts_at' => $start->toDateString(),
            'renews_at' => $start->copy()->addYear()->toDateString(),
            'auto_renew' => true,
            'last_paid_at' => $firstYearIncluded ? $start : null,
            'notes' => $firstYearIncluded
                ? "First year included with order {$order->reference}."
                : "Provisioned from order {$order->reference}.",
        ]);

        if (!$firstYearIncluded) {
            // Bill period one immediately, starting at the purchase date.
            $this->raiseInvoice($subscription, $start->toDateString(), $start->toDateString());
        }

        return $subscription;
    }

    /** Customer-initiated signup to a published plan. Bills period one up front. */
    public function subscribeToPlan(Customer $customer, SubscriptionPlan $plan, int $quantity = 1): Subscription
    {
        $quantity = max(1, $quantity);
        $today = Carbon::today();

        $subscription = Subscription::create([
            'reference' => Subscription::nextReference(),
            'customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'billing_period' => $plan->billing_period,
            'quantity' => $quantity,
            'unit_price' => $plan->price,
            'price' => $plan->price * $quantity,
            'status' => 'pending',
            'starts_at' => $today->toDateString(),
            'renews_at' => $this->addPeriod($today->copy(), $plan->billing_period)->toDateString(),
            'auto_renew' => true,
        ]);

        $this->raiseInvoice($subscription, $today->toDateString(), $today->toDateString());

        return $subscription->fresh();
    }

    /**
     * Return the payable invoice for the upcoming period, raising one if the
     * renewal is inside the billing window (or `$force` for an early renewal).
     */
    public function ensureDueInvoice(Subscription $subscription, bool $force = false): ?SubscriptionInvoice
    {
        if (in_array($subscription->status, ['cancelled', 'expired'], true) && !$force) {
            return null;
        }
        if ($open = $subscription->openInvoice()) {
            return $open;
        }
        if (!$force && !$subscription->is_due) {
            return null;
        }

        $periodStart = $subscription->renews_at->copy();

        return $this->raiseInvoice(
            $subscription,
            $periodStart->toDateString(),
            $periodStart->toDateString(),
        );
    }

    /**
     * Record payment for an invoice and roll the subscription forward one
     * period. Idempotent — a paid invoice is left untouched.
     */
    public function markInvoicePaid(
        SubscriptionInvoice $invoice,
        string $provider = 'manual',
        ?string $method = null,
        ?string $providerPaymentId = null,
    ): SubscriptionInvoice {
        if ($invoice->status === 'paid') {
            return $invoice;
        }

        return DB::transaction(function () use ($invoice, $provider, $method, $providerPaymentId) {
            $invoice->update([
                'status' => 'paid',
                'provider' => $provider,
                'payment_method' => $method,
                'provider_payment_id' => $providerPaymentId ?? $invoice->provider_payment_id,
                'paid_at' => now(),
            ]);

            $subscription = $invoice->subscription;
            $subscription->update([
                'status' => 'active',
                // period_end is the next renewal date; never move it backwards.
                'renews_at' => $invoice->period_end->greaterThan($subscription->renews_at)
                    ? $invoice->period_end
                    : $subscription->renews_at,
                'last_paid_at' => now(),
            ]);

            return $invoice->fresh('subscription');
        });
    }

    /** Attach a PayMongo hosted checkout to an invoice (no-op when unconfigured). */
    public function attachCheckout(SubscriptionInvoice $invoice): SubscriptionInvoice
    {
        if ($invoice->checkout_url || !$this->paymongo->isConfigured()) {
            return $invoice;
        }

        $link = $this->paymongo->createLinkForInvoice($invoice);
        if ($link) {
            $invoice->update([
                'provider' => 'paymongo',
                'provider_link_id' => $link['id'] ?? null,
                'checkout_url' => $link['checkout_url'] ?? null,
                'status' => 'awaiting',
            ]);
        }

        return $invoice->fresh();
    }

    /**
     * Ask PayMongo whether an awaiting invoice has actually been paid, and settle
     * it if so.
     *
     * Webhooks are the fast path, but they are easy to lose: the dashboard
     * webhook may never have been registered, the callback can fail, and in local
     * development PayMongo cannot reach the app at all. Polling the Link on read
     * means a customer who has paid always sees it reflected, at the cost of one
     * API call per open invoice.
     */
    public function reconcile(SubscriptionInvoice $invoice): SubscriptionInvoice
    {
        if ($invoice->status !== 'awaiting' || !$invoice->provider_link_id) {
            return $invoice;
        }

        $link = $this->paymongo->fetchLink($invoice->provider_link_id);
        if (!$link) {
            return $invoice;
        }

        if (($link['attributes']['status'] ?? null) !== 'paid') {
            return $invoice;
        }

        $payment = $this->paymongo->paidPaymentFromLink($link);

        return $this->markInvoicePaid(
            $invoice,
            provider: 'paymongo',
            method: $payment['method'] ?? null,
            providerPaymentId: $payment['id'] ?? null,
        );
    }

    /**
     * Reconcile every awaiting invoice in a set. Returns how many were settled.
     * Cheap when there is nothing outstanding — awaiting invoices without a
     * checkout link are skipped without an API call.
     */
    public function reconcileAll(iterable $invoices): int
    {
        $settled = 0;

        foreach ($invoices as $invoice) {
            if ($invoice->status !== 'awaiting' || !$invoice->provider_link_id) {
                continue;
            }
            if ($this->reconcile($invoice)->status === 'paid') {
                $settled++;
            }
        }

        return $settled;
    }

    /**
     * Reconcile a subscription's status with today's date: lapsed renewals go
     * past_due, and past_due beyond the grace window expires.
     */
    public function refreshStatus(Subscription $subscription): Subscription
    {
        if (in_array($subscription->status, ['cancelled', 'pending'], true)) {
            return $subscription;
        }

        $days = $subscription->days_until_renewal;
        $status = match (true) {
            $days < -Subscription::GRACE_DAYS => 'expired',
            $days < 0 => 'past_due',
            default => 'active',
        };

        if ($status !== $subscription->status) {
            $subscription->update(['status' => $status]);
        }

        return $subscription;
    }

    public function cancel(Subscription $subscription): Subscription
    {
        $subscription->update([
            'status' => 'cancelled',
            'auto_renew' => false,
            'cancelled_at' => now(),
        ]);

        // Withdraw anything still open — the customer no longer owes it.
        $subscription->invoices()
            ->whereIn('status', ['unpaid', 'awaiting'])
            ->update(['status' => 'void']);

        return $subscription->fresh();
    }

    /** Create the invoice row for one period. */
    private function raiseInvoice(Subscription $subscription, string $periodStart, string $dueDate): SubscriptionInvoice
    {
        $start = Carbon::parse($periodStart);
        $end = $this->addPeriod($start->copy(), $subscription->billing_period);
        $label = $subscription->billing_period === 'monthly' ? 'Monthly' : 'Yearly';

        return SubscriptionInvoice::create([
            'reference' => SubscriptionInvoice::nextReference(),
            'subscription_id' => $subscription->id,
            'customer_id' => $subscription->customer_id,
            'description' => sprintf(
                '%s — %s renewal (%s to %s)',
                $subscription->plan_name,
                strtolower($label),
                $start->format('M j, Y'),
                $end->copy()->subDay()->format('M j, Y'),
            ),
            'amount' => $subscription->price,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'due_date' => Carbon::parse($dueDate)->toDateString(),
            'status' => 'unpaid',
        ]);
    }

    /** Non-mutating — Carbon's add* modify in place, which silently shifted callers' dates. */
    private function addPeriod(Carbon $date, string $period): Carbon
    {
        return $period === 'monthly' ? $date->copy()->addMonth() : $date->copy()->addYear();
    }
}
