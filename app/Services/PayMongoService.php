<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
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
