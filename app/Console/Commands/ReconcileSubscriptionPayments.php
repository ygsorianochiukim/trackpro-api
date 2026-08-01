<?php

namespace App\Console\Commands;

use App\Models\SubscriptionInvoice;
use App\Services\PayMongoService;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;

/**
 * Ask PayMongo about every awaiting invoice and settle the ones already paid.
 *
 * Covers the cases a webhook can't: no webhook registered in the PayMongo
 * dashboard, a callback that failed or was missed, or local development where
 * PayMongo cannot reach the app. Safe to run any time — settling is idempotent.
 */
class ReconcileSubscriptionPayments extends Command
{
    protected $signature = 'subscriptions:reconcile
                            {--invoice= : Only this invoice reference, e.g. INV-000010}';

    protected $description = 'Settle subscription invoices that PayMongo has already been paid for';

    public function handle(SubscriptionService $subscriptions, PayMongoService $paymongo): int
    {
        if (!$paymongo->isConfigured()) {
            $this->error('PAYMONGO_SECRET_KEY is not set — nothing to reconcile against.');
            return self::FAILURE;
        }

        $query = SubscriptionInvoice::query()->whereNotNull('provider_link_id');

        if ($reference = $this->option('invoice')) {
            $query->where('reference', $reference);
        } else {
            $query->where('status', 'awaiting');
        }

        $invoices = $query->get();

        if ($invoices->isEmpty()) {
            $this->info('Nothing to reconcile.');
            return self::SUCCESS;
        }

        $settled = 0;

        foreach ($invoices as $invoice) {
            if ($invoice->status === 'paid') {
                $this->line("  {$invoice->reference}: already settled");
                continue;
            }

            $before = $invoice->status;
            $after = $subscriptions->reconcile($invoice);

            if ($after->status === 'paid') {
                $settled++;
                $this->info("  {$invoice->reference}: {$before} → paid (₱{$after->amount}, "
                    . ($after->payment_method ?? 'paymongo') . ') — subscription renewed to '
                    . $after->subscription->renews_at->toDateString());
            } else {
                $this->line("  {$invoice->reference}: still {$after->status} at PayMongo");
            }
        }

        $this->info("Done. {$settled} invoice(s) settled out of {$invoices->count()} checked.");

        return self::SUCCESS;
    }
}
