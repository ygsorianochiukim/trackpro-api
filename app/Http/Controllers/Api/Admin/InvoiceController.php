<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\InvoiceTemplate;
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
            'data' => $order->invoice ? InvoiceTemplate::fill($order->invoice) : $this->generate($order),
            'edited' => $order->invoice !== null,
        ]);
    }

    /** PUT /api/admin/orders/{order}/invoice */
    public function update(Request $request, Order $order): JsonResponse
    {
        $data = self::validated($request);

        $order->update(['invoice' => $data]);

        return response()->json(['data' => $order->invoice, 'edited' => true]);
    }

    /** DELETE /api/admin/orders/{order}/invoice — discard edits, regenerate from the order. */
    public function destroy(Order $order): JsonResponse
    {
        $order->update(['invoice' => null]);

        return response()->json(['data' => $this->generate($order), 'edited' => false]);
    }

    /**
     * Validate a saved invoice. Shared with the renewal invoice printout, so
     * both editors send the same shape.
     */
    public static function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:60'],
            'number' => ['required', 'string', 'max:60'],
            'date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
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
            'notes_label' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // Admin-added fields: extra bill-to lines, extra rows in the details box, extra sections.
            'bill_to_fields' => ['nullable', 'array', 'max:20'],
            'bill_to_fields.*.label' => ['nullable', 'string', 'max:60'],
            'bill_to_fields.*.value' => ['nullable', 'string', 'max:255'],
            'details' => ['nullable', 'array', 'max:20'],
            'details.*.label' => ['nullable', 'string', 'max:60'],
            'details.*.value' => ['nullable', 'string', 'max:255'],
            'sections' => ['nullable', 'array', 'max:10'],
            'sections.*.label' => ['nullable', 'string', 'max:60'],
            'sections.*.body' => ['nullable', 'string', 'max:2000'],
            // Letterhead and footer text (null = the company defaults).
            'from' => ['nullable', 'array'],
            'from.name' => ['nullable', 'string', 'max:255'],
            'from.lines' => ['nullable', 'string', 'max:1000'],
            'footer' => ['nullable', 'string', 'max:1000'],
        ]);
        $data['title'] = trim($data['title'] ?? '') ?: 'Invoice';
        $data['due_date'] ??= null;
        $data['discount'] ??= 0;
        $data['notes_label'] = trim($data['notes_label'] ?? '') ?: 'Notes';
        $data['bill_to_fields'] = self::filled($data['bill_to_fields'] ?? [], ['label', 'value']);
        $data['details'] = self::filled($data['details'] ?? [], ['label', 'value']);
        $data['sections'] = self::filled($data['sections'] ?? [], ['label', 'body']);
        $data['from'] ??= null;
        $data['footer'] ??= null;

        return $data;
    }

    /** Drop added rows the admin left completely blank. */
    private static function filled(array $rows, array $keys): array
    {
        return collect($rows)
            ->map(fn ($row) => collect($keys)->mapWithKeys(fn ($k) => [$k => trim($row[$k] ?? '')])->all())
            ->filter(fn ($row) => implode('', $row) !== '')
            ->values()
            ->all();
    }

    private function generate(Order $order): array
    {
        $order->loadMissing(['items', 'customer']);

        $items = $order->items->flatMap(function ($i) {
            $product = trim("{$i->product_name} ({$i->product_model})");
            $options = $i->options;
            if (!$options) {
                return [['description' => $product, 'qty' => $i->qty, 'unit_price' => $i->unit_price]];
            }

            // One line per thing the customer picked in the cart.
            $hardware = $options['hardware'] ?? null;
            $addOns = $options['add_ons'] ?? [];
            $plan = $options['plan'] ?? null;
            $bandwidth = $options['bandwidth'] ?? null;
            $lines = [[
                'description' => $hardware ? "{$product} — {$hardware['name']}" : $product,
                'qty' => $i->qty,
                'unit_price' => $i->unit_price - array_sum(array_column($addOns, 'price')),
            ]];
            foreach ($addOns as $addOn) {
                $lines[] = ['description' => "Add-on: {$addOn['name']}", 'qty' => $i->qty, 'unit_price' => $addOn['price']];
            }
            if ($plan) {
                $period = $plan['billing_period'] === 'yearly' ? 'first year' : 'first month';
                $lines[] = ['description' => "Platform subscription: {$plan['name']} ({$period})", 'qty' => $i->qty, 'unit_price' => $plan['price']];
            }
            if ($bandwidth && $plan) {
                $months = $plan['billing_period'] === 'yearly' ? 12 : 1;
                $lines[] = [
                    'description' => "Video bandwidth: {$bandwidth['name']} ({$months} " . ($months === 1 ? 'month' : 'months') . ')',
                    'qty' => $i->qty,
                    'unit_price' => $bandwidth['price'] * $months,
                ];
            }

            return $lines;
        })->all();

        if ($order->subscription_total > 0) {
            $items[] = ['description' => 'Subscription', 'qty' => 1, 'unit_price' => $order->subscription_total];
        }

        return InvoiceTemplate::start([
            'number' => preg_replace('/^TP-/', 'INV-', $order->reference),
            'date' => ($order->paid_at ?? $order->created_at)->toDateString(),
            'due_date' => null,
            'bill_to' => [
                'name' => $order->customer_name,
                'company' => $order->customer?->company,
                'email' => $order->customer_email,
                'phone' => $order->customer_phone,
                'address' => $order->delivery_address,
            ],
            'items' => $items,
            'discount' => 0,
        ]);
    }
}
