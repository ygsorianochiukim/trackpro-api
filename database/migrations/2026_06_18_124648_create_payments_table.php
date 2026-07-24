<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('paymongo'); // paymongo, manual, bank_transfer
            // PayMongo identifiers
            $table->string('provider_payment_id')->nullable()->index(); // pi_xxx or pay_xxx
            $table->string('provider_link_id')->nullable()->index();    // link_xxx (PayMongo Link)
            $table->string('payment_method')->nullable(); // gcash, grab_pay, paymaya, card
            $table->unsignedInteger('amount'); // PHP whole pesos
            $table->enum('status', [
                'pending', 'paid', 'failed', 'refunded', 'cancelled',
            ])->default('pending');
            $table->string('checkout_url')->nullable(); // PayMongo hosted checkout URL
            $table->json('raw_payload')->nullable(); // last webhook payload for debugging
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
