<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    /** GET /api/admin/payments — list + totals. */
    public function index(Request $request): JsonResponse
    {
        $payments = Payment::query()
            ->with('order:id,reference,customer_name')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('provider'), fn ($q) => $q->where('provider', $request->provider))
            ->latest()
            ->paginate(10)
            ->withQueryString();

        $totals = [
            'paid' => (int) Payment::where('status', 'paid')->sum('amount'),
            'pending' => (int) Payment::where('status', 'pending')->sum('amount'),
            'failed_count' => Payment::where('status', 'failed')->count(),
        ];

        return response()->json([
            'data' => $payments->items(),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'total' => $payments->total(),
                'has_more' => $payments->hasMorePages(),
            ],
            'totals' => $totals,
        ]);
    }

    /** POST /api/admin/orders/{id}/mark-paid — manual payment. */
    public function markPaid(Order $order): JsonResponse
    {
        $order->update([
            'payment_status' => 'paid',
            'status' => 'paid',
            'paid_at' => $order->paid_at ?? now(),
        ]);

        if (!$order->payment) {
            Payment::create([
                'order_id' => $order->id,
                'provider' => 'manual',
                'payment_method' => 'manual',
                'amount' => $order->hardware_subtotal,
                'status' => 'paid',
                'paid_at' => now(),
            ]);
        }

        return response()->json(['data' => $order->fresh(['items', 'payments'])]);
    }
}
