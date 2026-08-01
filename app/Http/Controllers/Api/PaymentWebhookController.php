<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\SubscriptionInvoice;
use App\Services\PayMongoService;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    public function __construct(
        private PayMongoService $paymongo,
        private SubscriptionService $subscriptions,
    ) {
    }

    /**
     * POST /api/paymongo/webhook
     * Receives PayMongo events: link.payment.paid, link.payment.failed, etc.
     * Webhook URL to register in PayMongo dashboard:
     *   https://your-api.com/api/paymongo/webhook
     */
    public function paymongo(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = (string) $request->header('Paymongo-Signature', '');
        $webhookSecret = (string) config('services.paymongo.webhook_secret');

        if (!$this->paymongo->verifyWebhookSignature($payload, $signature, $webhookSecret)) {
            Log::warning('PayMongo webhook signature verification failed', [
                'signature' => $signature,
            ]);
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $event = $request->json('data.attributes');
        $eventType = $event['type'] ?? '';
        $resourceData = $event['data'] ?? [];
        $resourceAttributes = $resourceData['attributes'] ?? [];

        Log::info('PayMongo webhook received', ['type' => $eventType]);

        // The resource shape differs per event: `payment.*` sends a payment (whose
        // attributes may carry `link_id`), while `link.payment.paid` sends the
        // *link* itself — so its id lives at `data.id` and the payment is nested
        // under `attributes.payments`. Collect every id we might match on and sort
        // them by prefix rather than assuming one layout.
        ['links' => $linkIds, 'payments' => $paymentIds] = $this->collectIds($resourceData, $resourceAttributes);
        $linkId = $linkIds[0] ?? null;
        $paymentId = $paymentIds[0] ?? null;

        // Bail out before querying when the event carries no identifiers at all.
        // Without this, both `when()` clauses are skipped and the query degrades
        // to `Payment::first()` — an unrelated payment would be matched and, for a
        // `payment.paid` event, marked paid along with its order.
        if (!$linkIds && !$paymentIds) {
            Log::warning('PayMongo webhook: event carried no link or payment id, ignored', [
                'type' => $eventType,
            ]);

            return response()->json(['message' => 'No identifiers in event, ignored'], 200);
        }

        $payment = Payment::query()
            ->when($linkIds, fn ($q) => $q->orWhereIn('provider_link_id', $linkIds))
            ->when($paymentIds, fn ($q) => $q->orWhereIn('provider_payment_id', $paymentIds))
            ->first();

        if (!$payment) {
            // Links are also raised for subscription renewals, which live in
            // their own table rather than `payments`.
            if ($handled = $this->handleSubscriptionInvoice($eventType, $linkIds, $paymentIds, $resourceAttributes)) {
                return $handled;
            }

            Log::warning('PayMongo webhook: no matching Payment record', [
                'link_id' => $linkId,
                'payment_id' => $paymentId,
            ]);
            return response()->json(['message' => 'No matching payment, ignored'], 200);
        }

        $payment->raw_payload = $event;
        if ($paymentId) {
            $payment->provider_payment_id = $paymentId;
        }
        $payment->payment_method = $resourceAttributes['source']['type'] ?? $payment->payment_method;

        switch ($eventType) {
            case 'link.payment.paid':
            case 'payment.paid':
                $payment->status = 'paid';
                $payment->paid_at = now();
                $payment->save();

                $payment->order()->update([
                    'payment_status' => 'paid',
                    'status' => 'paid',
                    'paid_at' => now(),
                ]);

                // A paid order starts the customer's yearly tracking subscription.
                if ($order = $payment->order()->with('items')->first()) {
                    $this->subscriptions->provisionForOrder($order);
                }
                break;

            case 'payment.failed':
                $payment->status = 'failed';
                $payment->save();
                $payment->order()->update(['payment_status' => 'failed']);
                break;

            case 'payment.refunded':
                $payment->status = 'refunded';
                $payment->save();
                $payment->order()->update(['payment_status' => 'refunded']);
                break;

            default:
                $payment->save();
                break;
        }

        return response()->json(['message' => 'Processed']);
    }

    /**
     * Settle a subscription renewal paid through its PayMongo link. Returns null
     * when the event doesn't belong to a subscription invoice, so the caller can
     * fall through to its own "unmatched" handling.
     */
    private function handleSubscriptionInvoice(
        string $eventType,
        array $linkIds,
        array $paymentIds,
        array $resourceAttributes,
    ): ?JsonResponse {
        if (!$linkIds && !$paymentIds) {
            return null;
        }

        $invoice = SubscriptionInvoice::query()
            ->when($linkIds, fn ($q) => $q->orWhereIn('provider_link_id', $linkIds))
            ->when($paymentIds, fn ($q) => $q->orWhereIn('provider_payment_id', $paymentIds))
            ->first();

        if (!$invoice) {
            return null;
        }

        switch ($eventType) {
            case 'link.payment.paid':
            case 'payment.paid':
                // A payment resource carries `source` directly. A link resource keeps
                // it on a nested payment whose shape varies (wrapped in `data` in
                // webhooks, flat in API responses, and sometimes absent entirely) —
                // paidPaymentFromLink already handles all three.
                $method = $resourceAttributes['source']['type']
                    ?? $this->paymongo->paidPaymentFromLink(['attributes' => $resourceAttributes])['method']
                    ?? null;

                $this->subscriptions->markInvoicePaid(
                    $invoice,
                    provider: 'paymongo',
                    method: $method,
                    providerPaymentId: $paymentIds[0] ?? null,
                );
                break;

            case 'payment.failed':
                // Leave it payable so the customer can retry from Billing.
                $invoice->update(['status' => 'unpaid', 'checkout_url' => null, 'provider_link_id' => null]);
                break;

            case 'payment.refunded':
                $invoice->update(['status' => 'void']);
                break;
        }

        Log::info('PayMongo webhook: subscription invoice processed', [
            'invoice' => $invoice->reference,
            'type' => $eventType,
        ]);

        return response()->json(['message' => 'Processed']);
    }

    /**
     * Pull every id worth matching on out of the event resource, split by kind.
     *
     * `link.payment.paid` delivers the link (`data.id` = `link_…`, payments nested
     * under `attributes.payments`); `payment.*` delivers the payment
     * (`data.id` = `pay_…`, sometimes with `attributes.link_id`). Classifying by
     * prefix means either shape — and any future one — resolves correctly.
     *
     * @return array{links: list<string>, payments: list<string>}
     */
    private function collectIds(array $resourceData, array $resourceAttributes): array
    {
        $candidates = [
            $resourceData['id'] ?? null,
            $resourceAttributes['link_id'] ?? null,
            $resourceAttributes['payment_id'] ?? null,
        ];

        foreach ($resourceAttributes['payments'] ?? [] as $entry) {
            $payment = $entry['data'] ?? $entry;
            $candidates[] = $payment['id'] ?? null;
        }

        $links = [];
        $payments = [];

        foreach ($candidates as $id) {
            if (!is_string($id) || $id === '') {
                continue;
            }
            if (str_starts_with($id, 'link_')) {
                $links[] = $id;
            } elseif (str_starts_with($id, 'pay_') || str_starts_with($id, 'pi_')) {
                $payments[] = $id;
            }
        }

        return ['links' => array_values(array_unique($links)), 'payments' => array_values(array_unique($payments))];
    }
}
