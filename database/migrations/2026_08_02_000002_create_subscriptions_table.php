<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique(); // e.g. SUB-000123
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_plan_id')->nullable()->constrained()->nullOnDelete();
            // The order that provisioned this subscription, when it came from a purchase.
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();

            // Snapshot of the plan at signup — plan prices change, invoices shouldn't.
            $table->string('plan_name');
            $table->enum('billing_period', ['yearly', 'monthly'])->default('yearly');
            $table->unsignedInteger('quantity')->default(1);  // devices covered
            $table->unsignedInteger('unit_price');             // per device, per period
            $table->unsignedInteger('price');                  // unit_price * quantity

            $table->enum('status', [
                'pending',   // awaiting first payment
                'active',
                'past_due',  // renewal date passed, invoice unpaid
                'expired',   // past_due beyond the grace window
                'cancelled',
            ])->default('pending');

            $table->date('starts_at');
            $table->date('renews_at')->index(); // the yearly renewal date
            $table->boolean('auto_renew')->default(true);
            $table->timestamp('last_paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
