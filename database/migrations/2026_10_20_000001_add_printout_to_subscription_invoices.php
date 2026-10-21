<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin-edited printable copy of a renewal invoice (null = generated as-is).
        // Only the printout changes — `amount` stays what the customer owes.
        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->json('printout')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->dropColumn('printout');
        });
    }
};
