<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('model');
            $table->string('category');
            $table->string('icon')->nullable();
            $table->unsignedInteger('price'); // PHP whole pesos
            $table->unsignedInteger('subscription')->default(0); // monthly PHP
            $table->string('tagline');
            $table->text('description')->nullable();
            $table->json('highlights');
            $table->boolean('featured')->default(false);
            $table->unsignedInteger('stock')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
