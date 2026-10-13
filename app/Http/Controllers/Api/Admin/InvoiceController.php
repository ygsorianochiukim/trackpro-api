<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Basic sales invoice for an order. It starts out generated from the order and
 * customer; once the admin edits and saves it, the saved copy is used instead.
 */
class InvoiceController extends Controller
{
    /** GET /api/admin/orders/{order}/invoice */
    public function show(Order $order): JsonResponse
    {
        return response()->json([
            'data' => $order->invoice ?? $this->generate($order),
            'edited' => $order->invoice !== null,
        ]);
    }

    /** PUT /api/admin/orders/{order}/invoice */
    public function update(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'number' => ['required', 'string', 'max:60'],
            'date' => ['required', 'date'],
            'bill_to' => ['required', 'array'],
            'bill_to.name' => ['required', 'string', 'max:255'],
            'bill_to.company' => ['nullable', 'string', 'max:255'],
            'bill_to.email' => ['nullable', 'string', 'max:255'],
            'bill_to.phone' => ['nullable', 'string', 'max:60'],
            'bill_to.address' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.qty' => ['required', 'integer', 'min:0'],
            'items.*.unit_price' => ['required', 'integer', 'min:0'],
            'discount' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['discount'] ??= 0;

        $order->update(['invoice' => $data]);

        return response()->json(['data' => $order->invoice, 'edited' => true]);
    }

    /** DELETE /api/admin/orders/{order}/invoice — discard edits, regenerate from the order. */
    public function destroy(Order $order): JsonResponse
    {
        $order->update(['invoice' => null]);

        return response()->json(['data' => $this->generate($order), 'edited' => false]);
    }

    private function generate(Order $order): array
    {
        $order->loadMissing(['items', 'customer']);

        $items = $order->items->map(fn ($i) => [
            'description' => trim("{$i->product_name} ({$i->product_model})"),
            'qty' => $i->qty,
            'unit_price' => $i->unit_price,
        ])->all();

        if ($order->subscription_total > 0) {
            $items[] = ['description' => 'Subscription', 'qty' => 1, 'unit_price' => $order->subscription_total];
        }

        return [
            'number' => preg_replace('/^TP-/', 'INV-', $order->reference),
            'date' => ($order->paid_at ?? $order->created_at)->toDateString(),
            'bill_to' => [
                'name' => $order->customer_name,
                'company' => $order->customer?->company,
                'email' => $order->customer_email,
                'phone' => $order->customer_phone,
                'address' => $order->delivery_address,
            ],
            'items' => $items,
            'discount' => 0,
            'notes' => 'Thank you for choosing TrackPro GPS.',
        ];
    }
}
