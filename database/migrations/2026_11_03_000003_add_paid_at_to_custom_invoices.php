<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A custom invoice marked paid is a sale: it joins the sales ledger
        // (reports, dashboard revenue, wallets) at `paid_at`.
        Schema::table('custom_invoices', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->index()->after('total');
            $table->string('payment_method', 40)->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('custom_invoices', function (Blueprint $table) {
            $table->dropColumn(['paid_at', 'payment_method']);
        });
    }
};
