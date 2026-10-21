<?php

namespace App\Support;

use App\Http\Controllers\Api\Admin\InvoiceController;
use App\Models\Setting;
use App\Models\SubscriptionInvoice;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The company's invoice template: letterhead, title, footer, default notes and
 * the admin's own fields and sections. Every invoice — order, renewal or
 * written from scratch — starts from it; a saved invoice keeps what it was
 * saved with, so changing the template never rewrites an issued invoice.
 */
class InvoiceTemplate
{
    private const KEY = 'invoice_template';

    /** `from` / `footer` null = the company defaults the site renders. */
    public static function defaults(): array
    {
        return [
            'title' => 'Invoice',
            'from' => null,
            'footer' => null,
            'notes_label' => 'Notes',
            'notes' => 'Thank you for choosing TrackPro GPS.',
            'bill_to_fields' => [],
            'details' => [],
            'sections' => [],
        ];
    }

    public static function get(): array
    {
        return array_replace(self::defaults(), Setting::get(self::KEY, []));
    }

    /** Save the template from a whole invoice sent by the editor, keeping only the template parts. */
    public static function saveFrom(Request $request): array
    {
        Setting::put(self::KEY, array_intersect_key(InvoiceController::validated($request), self::defaults()));

        return self::get();
    }

    /** A new invoice: the template, with the record's own details on top. */
    public static function start(array $invoice): array
    {
        return array_replace(self::get(), $invoice);
    }

    /** A saved invoice, with anything it predates (e.g. added fields) taken from the template. */
    public static function fill(array $saved): array
    {
        return array_replace(self::get(), $saved);
    }

    /** A renewal invoice as the customer receives it — the admin's saved copy, or generated. */
    public static function forRenewal(SubscriptionInvoice $invoice): array
    {
        return $invoice->printout ? self::fill($invoice->printout) : self::generateRenewal($invoice);
    }

    private static function generateRenewal(SubscriptionInvoice $invoice): array
    {
        $invoice->loadMissing(['customer', 'subscription']);
        $customer = $invoice->customer;
        $subscription = $invoice->subscription;

        // Plan prices can change after an invoice is raised — the invoice amount is what's owed.
        $qty = $subscription?->quantity ?? 1;
        $unit = $subscription?->unit_price ?? $invoice->amount;
        if ($qty < 1 || $qty * $unit !== $invoice->amount) {
            [$qty, $unit] = [1, $invoice->amount];
        }

        // The stored description ends with its own "(date to date)"; a period stores the
        // next renewal date as its end, so the service runs through the day before.
        $description = preg_replace('/\s*\([^)]*\)\s*$/', '', $invoice->description);
        $period = $invoice->period_start->format('M j, Y') . ' – ' . $invoice->period_end->copy()->subDay()->format('M j, Y');

        // Renewal notes say where the invoice stands; the template's fields and sections still apply.
        $notes = match ($invoice->status) {
            'paid' => [
                'notes_label' => 'Payment received',
                'notes' => 'Paid on ' . $invoice->paid_at?->timezone('Asia/Manila')->format('M j, Y')
                    . ($invoice->payment_method ? ' via ' . strtoupper($invoice->payment_method) : '') . '. Thank you!',
            ],
            'void' => ['notes_label' => 'Note', 'notes' => 'This invoice has been voided and no payment is due.'],
            default => [
                'notes_label' => 'Payment terms',
                'notes' => 'Please pay on or before ' . $invoice->due_date->format('M j, Y')
                    . ' to keep your GPS tracking service active. Pay online from your TrackPro account under Billing.',
            ],
        };

        return self::start([
            'number' => $invoice->reference,
            'date' => ($invoice->created_at ?? Carbon::today())->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'bill_to' => [
                'name' => $customer?->name ?? '',
                'company' => $customer?->company,
                'email' => $customer?->email,
                'phone' => $customer?->phone,
                'address' => null,
            ],
            'items' => [[
                'description' => "{$description} · Service period {$period}",
                'qty' => $qty,
                'unit_price' => $unit,
            ]],
            'discount' => 0,
        ] + $notes);
    }
}
