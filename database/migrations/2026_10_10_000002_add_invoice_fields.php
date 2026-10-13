<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin-edited sales invoice for an order (null = generated from the order as-is).
        Schema::table('orders', function (Blueprint $table) {
            $table->json('invoice')->nullable()->after('notes');
        });

        // Supplier invoice / OR number an expense is backed by.
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('invoice_reference', 100)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('invoice');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('invoice_reference');
        });
    }
};
