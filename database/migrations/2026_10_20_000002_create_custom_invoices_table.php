<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Invoices the admin writes from scratch — not tied to an order or subscription.
        Schema::create('custom_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 60)->index();
            $table->date('date');
            $table->string('customer_name');   // copied out of `invoice` for listing / search
            $table->unsignedInteger('total'); // whole pesos, after discount
            $table->json('invoice');           // same shape as an order's saved invoice
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_invoices');
    }
};
