<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Billing documents for subscription periods. One row per period the customer
     * is asked to pay for — this is what the customer's Billing page lists.
     */
    public function up(): void
    {
        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique(); // e.g. INV-000123
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->string('description');
            $table->unsignedInteger('amount'); // whole pesos
            $table->date('period_start');
            $table->date('period_end');        // becomes the next renewal date once paid
            $table->date('due_date');

            $table->enum('status', ['unpaid', 'awaiting', 'paid', 'void'])->default('unpaid');

            // Payment details — mirrors the `payments` table, which is order-bound.
            $table->string('provider')->nullable();            // paymongo, manual, temporary
            $table->string('provider_link_id')->nullable()->index();
            $table->string('provider_payment_id')->nullable()->index();
            $table->string('payment_method')->nullable();
            $table->string('checkout_url')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            // At most one open invoice per period, so repeated "renew" clicks and the
            // billing cron can't double-charge.
            $table->unique(['subscription_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_invoices');
    }
};
