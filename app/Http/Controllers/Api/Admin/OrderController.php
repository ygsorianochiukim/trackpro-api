<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /** GET /api/admin/orders — filterable list. */
    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->withCount('items')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', $request->payment_status))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%' . $request->q . '%';
                $q->where(fn ($w) => $w
                    ->where('reference', 'like', $term)
                    ->orWhere('customer_name', 'like', $term)
                    ->orWhere('customer_email', 'like', $term));
            })
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return response()->json([
            'data' => $orders->items(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'total' => $orders->total(),
                'has_more' => $orders->hasMorePages(),
            ],
        ]);
    }

    /** GET /api/admin/orders/{id} */
    public function show(Order $order): JsonResponse
    {
        $order->load(['items', 'payments']);
        return response()->json(['data' => $order]);
    }

    /** PATCH /api/admin/orders/{id} */
    public function update(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:pending,paid,preparing,shipped,completed,cancelled'],
            'payment_status' => ['required', 'in:unpaid,awaiting,paid,failed,refunded'],
        ]);

        $updates = $data;
        if ($data['payment_status'] === 'paid' && !$order->paid_at) {
            $updates['paid_at'] = now();
        }
        if ($data['status'] === 'shipped' && !$order->shipped_at) {
            $updates['shipped_at'] = now();
        }

        $order->update($updates);

        return response()->json(['data' => $order->fresh(['items', 'payments'])]);
    }
}
