<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique(); // e.g. TP-0001
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            // Snapshot of contact info at time of order
            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('customer_phone');
            $table->text('delivery_address');
            $table->text('notes')->nullable();
            // Totals in whole pesos
            $table->unsignedInteger('hardware_subtotal');
            $table->unsignedInteger('subscription_total')->default(0);
            // Status workflow
            $table->enum('status', [
                'pending', 'paid', 'preparing', 'shipped', 'completed', 'cancelled',
            ])->default('pending');
            $table->enum('payment_status', [
                'unpaid', 'awaiting', 'paid', 'failed', 'refunded',
            ])->default('unpaid');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
