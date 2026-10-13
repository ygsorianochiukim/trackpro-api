<?php

use Database\Seeders\AddOnSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional add-ons (sensors, relays, cameras…) shown on the homepage, previously
 * hard-coded in the frontend. Seeds the existing lineup so the site looks the
 * same after deploy; from then on they are managed from Admin → Add-ons.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('add_ons', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('subtitle')->nullable();
            $table->unsignedInteger('price'); // whole pesos, one-time
            $table->string('icon')->nullable();
            $table->text('description')->nullable();
            $table->json('features')->nullable(); // string[] — "Key Features"
            $table->json('devices')->nullable();  // string[] — "Applicable Devices"
            $table->string('warranty')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        (new AddOnSeeder())->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('add_ons');
    }
};
