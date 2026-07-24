<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\PayMongoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    public function __construct(private PayMongoService $paymongo)
    {
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

        // Find related Payment record by link_id or payment_id
        $linkId = $resourceAttributes['link_id'] ?? null;
        $paymentId = $resourceData['id'] ?? null;

        $payment = Payment::query()
            ->when($linkId, fn ($q) => $q->orWhere('provider_link_id', $linkId))
            ->when($paymentId, fn ($q) => $q->orWhere('provider_payment_id', $paymentId))
            ->first();

        if (!$payment) {
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
}
