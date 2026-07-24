<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            // Snapshot of product details at time of order
            $table->string('product_name');
            $table->string('product_model');
            $table->unsignedInteger('unit_price');
            $table->unsignedInteger('unit_subscription')->default(0);
            $table->unsignedInteger('qty');
            $table->unsignedInteger('line_total'); // unit_price * qty
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
