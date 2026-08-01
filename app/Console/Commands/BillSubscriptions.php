<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;

/**
 * Nightly subscription housekeeping: reconcile statuses and raise renewal
 * invoices for anything inside the billing window. Idempotent — running it
 * twice in a day cannot double-bill (see SubscriptionService).
 *
 * The customer's account pages call the same service on read, so billing still
 * works if this command is never scheduled; the schedule just means a renewal
 * notice exists before the customer next signs in.
 */
class BillSubscriptions extends Command
{
    protected $signature = 'subscriptions:bill {--dry-run : Report what would happen without writing}';

    protected $description = 'Raise renewal invoices and refresh subscription statuses';

    public function handle(SubscriptionService $subscriptions): int
    {
        $raised = 0;
        $statusChanges = 0;
        $dryRun = (bool) $this->option('dry-run');

        Subscription::whereNotIn('status', ['cancelled'])
            ->orderBy('renews_at')
            ->chunkById(100, function ($chunk) use ($subscriptions, $dryRun, &$raised, &$statusChanges) {
                foreach ($chunk as $subscription) {
                    $before = $subscription->status;

                    if ($dryRun) {
                        if ($subscription->is_due && !$subscription->openInvoice()) {
                            $this->line("  would invoice {$subscription->reference} (renews {$subscription->renews_at->toDateString()})");
                            $raised++;
                        }
                        continue;
                    }

                    $subscriptions->refreshStatus($subscription);
                    if ($subscription->status !== $before) {
                        $statusChanges++;
                        $this->line("  {$subscription->reference}: {$before} → {$subscription->status}");
                    }

                    $existing = $subscription->openInvoice();
                    $invoice = $subscriptions->ensureDueInvoice($subscription);
                    if ($invoice && !$existing) {
                        // No PayMongo link here on purpose: it is created when the
                        // customer actually clicks Pay, so the gateway account
                        // doesn't collect links for invoices nobody opens.
                        $raised++;
                        $this->line("  invoiced {$subscription->reference} → {$invoice->reference} (₱{$invoice->amount}, due {$invoice->due_date->toDateString()})");
                    }
                }
            });

        $this->info($dryRun
            ? "Dry run: {$raised} invoice(s) would be raised."
            : "Done. {$raised} invoice(s) raised, {$statusChanges} status change(s).");

        return self::SUCCESS;
    }
}
