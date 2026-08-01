<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\PayMongoService;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class OrderController extends Controller
{
    public function __construct(
        private PayMongoService $paymongo,
        private SubscriptionService $subscriptions,
    ) {
    }

    /**
     * POST /api/orders (auth:sanctum)
     * Body: { items: [{ slug, qty }], customer: { name, email, phone, address, notes? } }
     *
     * Requires a signed-in customer with a verified email. The order is linked
     * to that customer.
     */
    public function store(Request $request): JsonResponse
    {
        $customer = $request->user();
        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'A customer account is required to place an order.'], 403);
        }
        if (!$customer->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Please verify your email before placing an order.',
                'code' => 'email_unverified',
            ], 403);
        }

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.slug' => ['required', 'string'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:100'],
            'customer.name' => ['required', 'string', 'max:120'],
            'customer.email' => ['required', 'email', 'max:120'],
            'customer.phone' => ['required', 'string', 'max:32'],
            'customer.address' => ['required', 'string', 'max:500'],
            'customer.notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $order = DB::transaction(function () use ($data, $customer) {
                // Re-resolve products from DB — never trust client-sent prices
                $slugs = array_column($data['items'], 'slug');
                $products = Product::whereIn('slug', $slugs)
                    ->where('is_active', true)
                    ->get()
                    ->keyBy('slug');

                $hardwareSubtotal = 0;
                $subscriptionTotal = 0;
                $itemsToCreate = [];

                foreach ($data['items'] as $item) {
                    $product = $products->get($item['slug']);
                    if (!$product) {
                        abort(422, "Product not found: {$item['slug']}");
                    }
                    if ($product->stock < $item['qty']) {
                        abort(422, "Insufficient stock for {$product->name} (have {$product->stock}, requested {$item['qty']}).");
                    }

                    $lineTotal = $product->price * $item['qty'];
                    $hardwareSubtotal += $lineTotal;
                    $subscriptionTotal += $product->subscription * $item['qty'];

                    $itemsToCreate[] = [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'product_model' => $product->model,
                        'unit_price' => $product->price,
                        'unit_subscription' => $product->subscription,
                        'qty' => $item['qty'],
                        'line_total' => $lineTotal,
                    ];

                    $product->decrement('stock', $item['qty']);
                }

                $order = Order::create([
                    'reference' => Order::nextReference(),
                    'customer_id' => $customer?->id,
                    'customer_name' => $data['customer']['name'],
                    'customer_email' => $data['customer']['email'],
                    'customer_phone' => $data['customer']['phone'],
                    'delivery_address' => $data['customer']['address'],
                    'notes' => $data['customer']['notes'] ?? null,
                    'hardware_subtotal' => $hardwareSubtotal,
                    'subscription_total' => $subscriptionTotal,
                    'status' => 'pending',
                    'payment_status' => 'unpaid',
                ]);

                $order->items()->createMany($itemsToCreate);

                return $order->load('items');
            });
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Create a PayMongo checkout link if configured
        $payment = null;
        if ($this->paymongo->isConfigured()) {
            try {
                $payment = $this->paymongo->createLinkForOrder($order);
                $order->update(['payment_status' => 'awaiting']);
            } catch (Throwable $e) {
                report($e); // order is created — payment link can be retried from admin
            }
        }

        return response()->json([
            'order' => $order->fresh(['items']),
            'payment' => $payment,
            'checkout_url' => $payment?->checkout_url,
        ], 201);
    }

    /** GET /api/orders/{reference} (auth:sanctum) — owner only. */
    public function show(Request $request, string $reference): JsonResponse
    {
        $order = Order::with(['items', 'payment'])
            ->where('reference', $reference)
            ->firstOrFail();

        // Own-orders only: 404 (not 403) so references aren't enumerable.
        abort_unless($order->customer_id === $request->user()->id, 404);

        return response()->json(['data' => $order]);
    }

    /**
     * POST /api/orders/{reference}/pay
     *
     * TEMPORARY payment stand-in. Marks the order paid and records a manual
     * payment, without a real gateway. Idempotent — calling it on an
     * already-paid order is a no-op.
     *
     * ⚠️  Replace before going live: a real flow must verify payment server-side
     * (PayMongo link + webhook in PaymentWebhookController), never trust the
     * client to declare itself paid.
     */
    public function payTemporary(Request $request, string $reference): JsonResponse
    {
        $order = Order::where('reference', $reference)->firstOrFail();

        // Own-orders only.
        abort_unless($order->customer_id === $request->user()->id, 404);

        if ($order->payment_status !== 'paid') {
            $order->update([
                'payment_status' => 'paid',
                'status' => $order->status === 'pending' ? 'paid' : $order->status,
                'paid_at' => now(),
            ]);

            Payment::create([
                'order_id' => $order->id,
                'provider' => 'temporary',
                'payment_method' => 'temporary',
                'amount' => $order->hardware_subtotal,
                'status' => 'paid',
                'paid_at' => now(),
            ]);
        }

        // Paying for the hardware starts the yearly tracking subscription.
        $subscription = $this->subscriptions->provisionForOrder($order->fresh(['items']));

        return response()->json([
            'data' => $order->fresh(['items', 'payment']),
            'subscription' => $subscription?->only(['reference', 'plan_name', 'price', 'status', 'renews_at']),
        ]);
    }

    /** GET /api/customer/orders — authenticated customer's order history */
    public function mine(Request $request): JsonResponse
    {
        $orders = $request->user()
            ->orders()
            ->with(['items', 'payment'])
            ->latest()
            ->get();

        return response()->json(['data' => $orders]);
    }
}
