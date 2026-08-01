<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\SubscriptionInvoice;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin PayMongo Links API wrapper.
 * Docs: https://developers.paymongo.com/reference/links-api
 *
 * Uses the Links API (simpler than Checkout Sessions) — creates a hosted checkout URL
 * that supports GCash, GrabPay, PayMaya, and credit cards.
 */
class PayMongoService
{
    private string $secretKey;
    private string $baseUrl = 'https://api.paymongo.com/v1';

    public function __construct()
    {
        $this->secretKey = (string) config('services.paymongo.secret');
    }

    public function isConfigured(): bool
    {
        return $this->secretKey !== '';
    }

    /**
     * Create a PayMongo payment link for an order.
     * Returns the Payment record (with checkout_url populated).
     */
    public function createLinkForOrder(Order $order): Payment
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('PayMongo secret key is not configured.');
        }

        $amountCentavos = $order->hardware_subtotal * 100; // PayMongo uses centavos
        $description = "TrackPro Order {$order->reference}";

        $response = $this->postWithAuth('/links', [
            'data' => [
                'attributes' => [
                    'amount' => $amountCentavos,
                    'description' => $description,
                    'remarks' => "Order ID: {$order->id} ({$order->customer_name})",
                ],
            ],
        ]);

        if (!$response->successful()) {
            Log::error('PayMongo link creation failed', [
                'order_id' => $order->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Failed to create PayMongo link: ' . $response->body());
        }

        $body = $response->json('data');
        $linkId = $body['id'] ?? null;
        $checkoutUrl = $body['attributes']['checkout_url'] ?? null;

        return Payment::create([
            'order_id' => $order->id,
            'provider' => 'paymongo',
            'provider_link_id' => $linkId,
            'amount' => $order->hardware_subtotal,
            'status' => 'pending',
            'checkout_url' => $checkoutUrl,
            'raw_payload' => $body,
        ]);
    }

    /**
     * Create a PayMongo payment link for a subscription invoice (yearly renewal).
     * Returns the raw link data (`id`, `attributes.checkout_url`, …) or null when
     * the call fails — a failed link must not block the invoice from existing,
     * since it can still be settled manually from the admin.
     */
    public function createLinkForInvoice(SubscriptionInvoice $invoice): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $response = $this->postWithAuth('/links', [
            'data' => [
                'attributes' => [
                    'amount' => $invoice->amount * 100, // centavos
                    'description' => "TrackPro Subscription {$invoice->reference}",
                    'remarks' => $invoice->description,
                ],
            ],
        ]);

        if (!$response->successful()) {
            Log::error('PayMongo subscription link creation failed', [
                'invoice_id' => $invoice->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        }

        $body = $response->json('data');

        return [
            'id' => $body['id'] ?? null,
            'checkout_url' => $body['attributes']['checkout_url'] ?? null,
        ];
    }

    /**
     * Fetch a Link by id, so payment state can be confirmed by asking PayMongo
     * instead of waiting for a webhook. Returns the `data` object or null.
     *
     * This is what makes payment settlement work when no webhook is registered
     * (`PAYMONGO_WEBHOOK_SECRET` empty), when the callback was missed, or in local
     * development where PayMongo cannot reach the app at all.
     */
    public function fetchLink(string $linkId): ?array
    {
        if (!$this->isConfigured() || $linkId === '') {
            return null;
        }

        try {
            // Short timeout on purpose: this runs while an account page is being
            // rendered, so a slow gateway must not hold the page hostage. A
            // failure just leaves the invoice awaiting until the next check.
            $response = Http::withBasicAuth($this->secretKey, '')
                ->acceptJson()
                ->timeout(6)
                ->get($this->baseUrl . '/links/' . $linkId);
        } catch (\Throwable $e) {
            Log::warning('PayMongo link fetch failed', ['link_id' => $linkId, 'error' => $e->getMessage()]);
            return null;
        }

        if (!$response->successful()) {
            Log::warning('PayMongo link fetch returned an error', [
                'link_id' => $linkId,
                'status' => $response->status(),
            ]);
            return null;
        }

        return $response->json('data');
    }

    /** Fetch a Payment by id. Unlike a Link's embedded payments, this has `source`. */
    public function fetchPayment(string $paymentId): ?array
    {
        if (!$this->isConfigured() || $paymentId === '') {
            return null;
        }

        try {
            $response = Http::withBasicAuth($this->secretKey, '')
                ->acceptJson()
                ->timeout(6)
                ->get($this->baseUrl . '/payments/' . $paymentId);
        } catch (\Throwable $e) {
            Log::warning('PayMongo payment fetch failed', ['payment_id' => $paymentId, 'error' => $e->getMessage()]);
            return null;
        }

        return $response->successful() ? $response->json('data') : null;
    }

    /**
     * Pull the settled payment out of a Link payload, if there is one.
     * Returns `['id' => 'pay_…', 'method' => 'qrph'|'gcash'|…|null]` or null.
     *
     * A Link's embedded payments are abbreviated — verified against a live link,
     * the entry is flat (no `data` wrapper) and has no `source`, so the payment
     * method needs a second lookup. Both shapes are accepted because the webhook
     * payload nests the payment under `data`.
     */
    public function paidPaymentFromLink(array $link): ?array
    {
        foreach ($link['attributes']['payments'] ?? [] as $entry) {
            $payment = $entry['data'] ?? $entry;
            $attributes = $payment['attributes'] ?? [];

            if (($attributes['status'] ?? null) !== 'paid') {
                continue;
            }

            $id = $payment['id'] ?? null;
            $method = $attributes['source']['type'] ?? ($attributes['payment_method_type'] ?? null);

            // Not in the link payload — ask for the payment itself. One extra call,
            // only when settling, so the receipt can name the method used.
            if (!$method && is_string($id)) {
                $method = $this->fetchPayment($id)['attributes']['source']['type'] ?? null;
            }

            return ['id' => $id, 'method' => $method];
        }

        return null;
    }

    /**
     * Verify PayMongo webhook signature.
     * PayMongo sends `Paymongo-Signature: t=TIMESTAMP,te=TEST_SIG,li=LIVE_SIG`
     */
    public function verifyWebhookSignature(string $payload, string $signatureHeader, string $webhookSecret): bool
    {
        if ($webhookSecret === '') {
            // No secret configured — skip verification (dev mode). NOT safe for production.
            return true;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key] = $value;
        }

        $timestamp = $parts['t'] ?? '';
        $providedSig = ($parts['li'] ?? '') ?: ($parts['te'] ?? '');
        if ($timestamp === '' || $providedSig === '') {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $webhookSecret);
        return hash_equals($expected, $providedSig);
    }

    private function postWithAuth(string $path, array $body): Response
    {
        return Http::withBasicAuth($this->secretKey, '')
            ->acceptJson()
            ->asJson()
            ->post($this->baseUrl . $path, $body);
    }
}
