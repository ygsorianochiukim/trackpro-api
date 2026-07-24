<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('category')->default('Other'); // Inventory, Salaries, Rent, Utilities, Marketing, Logistics, Other
            $table->unsignedInteger('amount'); // whole pesos
            $table->date('incurred_on');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('incurred_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
