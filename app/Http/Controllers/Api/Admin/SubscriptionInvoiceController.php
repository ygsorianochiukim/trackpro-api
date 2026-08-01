<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionInvoice;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Subscription billing for the admin: every renewal invoice, plus manual
 * settlement for customers who pay in cash / GCash / bank transfer.
 */
class SubscriptionInvoiceController extends Controller
{
    public function __construct(private SubscriptionService $subscriptions)
    {
    }

    /** GET /api/admin/subscription-invoices — filters: status, search, overdue=1 */
    public function index(Request $request): JsonResponse
    {
        // Settle anything already paid at PayMongo that we never got a webhook
        // for, so the admin list can't disagree with the gateway.
        $this->subscriptions->reconcileAll(
            SubscriptionInvoice::where('status', 'awaiting')
                ->whereNotNull('provider_link_id')
                ->limit(25)
                ->get()
        );

        $query = SubscriptionInvoice::with([
            'customer:id,name,email',
            'subscription:id,reference,plan_name',
        ]);

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        if ($request->boolean('overdue')) {
            $query->whereIn('status', ['unpaid', 'awaiting'])
                ->where('due_date', '<', Carbon::today()->toDateString());
        }
        if ($search = trim($request->string('search')->toString())) {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"))
                    ->orWhereHas('subscription', fn ($s) => $s->where('reference', 'like', "%{$search}%"));
            });
        }

        $page = $query->orderByDesc('due_date')->orderByDesc('id')->paginate(15)->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (SubscriptionInvoice $i) => $this->payload($i))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'has_more' => $page->hasMorePages(),
            ],
            'totals' => [
                'outstanding' => (int) SubscriptionInvoice::whereIn('status', ['unpaid', 'awaiting'])->sum('amount'),
                'overdue' => (int) SubscriptionInvoice::whereIn('status', ['unpaid', 'awaiting'])
                    ->where('due_date', '<', Carbon::today()->toDateString())
                    ->sum('amount'),
                'collected_this_year' => (int) SubscriptionInvoice::where('status', 'paid')
                    ->whereYear('paid_at', Carbon::today()->year)
                    ->sum('amount'),
            ],
        ]);
    }

    /**
     * POST /api/admin/subscription-invoices/{invoice}/mark-paid
     * Settles the invoice and rolls the subscription's renewal date forward.
     */
    public function markPaid(Request $request, SubscriptionInvoice $invoice): JsonResponse
    {
        $data = $request->validate([
            'payment_method' => ['nullable', 'string', 'max:60'],
        ]);

        $invoice = $this->subscriptions->markInvoicePaid(
            $invoice,
            provider: 'manual',
            method: $data['payment_method'] ?? 'manual',
        );

        return response()->json(['data' => $this->payload($invoice->fresh(['customer', 'subscription']))]);
    }

    /** POST /api/admin/subscription-invoices/{invoice}/void — cancel an unpaid invoice. */
    public function void(SubscriptionInvoice $invoice): JsonResponse
    {
        if ($invoice->status === 'paid') {
            return response()->json(['message' => 'A paid invoice cannot be voided.'], 422);
        }

        $invoice->update(['status' => 'void']);

        return response()->json(['data' => $this->payload($invoice->fresh(['customer', 'subscription']))]);
    }

    private function payload(SubscriptionInvoice $i): array
    {
        return [
            'id' => $i->id,
            'reference' => $i->reference,
            'customer' => $i->customer ? ['id' => $i->customer->id, 'name' => $i->customer->name, 'email' => $i->customer->email] : null,
            'subscription' => $i->subscription
                ? ['id' => $i->subscription->id, 'reference' => $i->subscription->reference, 'plan_name' => $i->subscription->plan_name]
                : null,
            'description' => $i->description,
            'amount' => $i->amount,
            'period_start' => $i->period_start?->toDateString(),
            'period_end' => $i->period_end?->toDateString(),
            'due_date' => $i->due_date?->toDateString(),
            'status' => $i->status,
            'provider' => $i->provider,
            'payment_method' => $i->payment_method,
            'checkout_url' => $i->checkout_url,
            'paid_at' => $i->paid_at?->toIso8601String(),
            // Due *today* is not yet overdue — compare dates, not timestamps.
            'is_overdue' => $i->isPayable() && $i->due_date?->lt(Carbon::today()),
        ];
    }
}
