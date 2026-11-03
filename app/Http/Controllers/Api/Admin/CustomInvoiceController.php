<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomInvoice;
use App\Support\InvoiceTemplate;
use App\Support\SalesLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Invoices the admin writes from scratch in the invoice editor — for sales
 * and services that don't go through an order or a subscription.
 */
class CustomInvoiceController extends Controller
{
    /** GET /api/admin/custom-invoices — filter: search (number or customer) */
    public function index(Request $request): JsonResponse
    {
        $query = CustomInvoice::query();

        if ($search = trim($request->string('search')->toString())) {
            $query->where(fn ($q) => $q
                ->where('number', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%"));
        }

        $page = $query->orderByDesc('date')->orderByDesc('id')->paginate(15)->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (CustomInvoice $i) => $this->summary($i))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'has_more' => $page->hasMorePages(),
            ],
        ]);
    }

    /** GET /api/admin/custom-invoices/new — a blank invoice with the next number. */
    public function blank(): JsonResponse
    {
        return response()->json(['data' => InvoiceTemplate::start([
            'number' => CustomInvoice::nextNumber(),
            'date' => Carbon::today()->toDateString(),
            'due_date' => null,
            'bill_to' => ['name' => '', 'company' => null, 'email' => null, 'phone' => null, 'address' => null],
            'items' => [['description' => '', 'qty' => 1, 'unit_price' => 0]],
            'discount' => 0,
        ])]);
    }

    /** POST /api/admin/custom-invoices */
    public function store(Request $request): JsonResponse
    {
        $invoice = (new CustomInvoice)->fillFromInvoice(InvoiceController::validated($request));
        $invoice->save();

        return response()->json(['id' => $invoice->id, 'data' => $invoice->invoice, 'edited' => true], 201);
    }

    /** GET /api/admin/custom-invoices/{invoice} */
    public function show(CustomInvoice $invoice): JsonResponse
    {
        return response()->json([
            'id' => $invoice->id,
            'data' => InvoiceTemplate::fill($invoice->invoice),
            'edited' => true,
            'payment' => $this->payment($invoice),
        ]);
    }

    /**
     * POST /api/admin/custom-invoices/{invoice}/mark-paid
     * Records the money as received — from then on the invoice counts as a
     * sale in the reports. Idempotent: an already-paid invoice is left as is.
     */
    public function markPaid(Request $request, CustomInvoice $invoice): JsonResponse
    {
        $data = $request->validate([
            'payment_method' => ['nullable', 'string', 'max:40'],
            // When the cash actually came in (default now) — so back-dated sales land on the right day.
            'paid_at' => ['nullable', 'date'],
        ]);

        if (!$invoice->isPaid()) {
            $invoice->update([
                'paid_at' => !empty($data['paid_at']) ? Carbon::parse($data['paid_at'], SalesLedger::TIMEZONE) : now(),
                'payment_method' => $data['payment_method'] ?? 'manual',
            ]);
        }

        return response()->json(['id' => $invoice->id, 'payment' => $this->payment($invoice->fresh())]);
    }

    /** DELETE /api/admin/custom-invoices/{invoice}/mark-paid — undo: it leaves the sales reports again. */
    public function unmarkPaid(CustomInvoice $invoice): JsonResponse
    {
        $invoice->update(['paid_at' => null, 'payment_method' => null]);

        return response()->json(['id' => $invoice->id, 'payment' => $this->payment($invoice->fresh())]);
    }

    private function payment(CustomInvoice $i): array
    {
        return [
            'paid' => $i->isPaid(),
            'paid_at' => $i->paid_at?->toIso8601String(),
            'payment_method' => $i->payment_method,
        ];
    }

    /** PUT /api/admin/custom-invoices/{invoice} */
    public function update(Request $request, CustomInvoice $invoice): JsonResponse
    {
        $invoice->fillFromInvoice(InvoiceController::validated($request))->save();

        return response()->json(['id' => $invoice->id, 'data' => $invoice->invoice, 'edited' => true]);
    }

    /** DELETE /api/admin/custom-invoices/{invoice} */
    public function destroy(CustomInvoice $invoice): JsonResponse
    {
        $invoice->delete();

        return response()->json(['ok' => true]);
    }

    private function summary(CustomInvoice $i): array
    {
        return [
            'id' => $i->id,
            'number' => $i->number,
            'title' => $i->invoice['title'] ?? 'Invoice',
            'date' => $i->date->toDateString(),
            'customer_name' => $i->customer_name,
            'company' => $i->invoice['bill_to']['company'] ?? null,
            'total' => $i->total,
            'paid_at' => $i->paid_at?->toIso8601String(),
            'payment_method' => $i->payment_method,
            'updated_at' => $i->updated_at?->toIso8601String(),
        ];
    }
}
