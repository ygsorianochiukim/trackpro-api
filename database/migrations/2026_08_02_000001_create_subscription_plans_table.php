<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            // Whole pesos, charged once per period. Only 'yearly' ships today —
            // the column exists so a monthly plan can be added without a migration.
            $table->unsignedInteger('price');
            $table->enum('billing_period', ['yearly', 'monthly'])->default('yearly');
            $table->json('features')->nullable(); // string[] shown on the plan card
            $table->boolean('is_default')->default(false); // used when provisioning from an order
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
