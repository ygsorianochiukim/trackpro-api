<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // null = not set for this product: video bandwidth is offered by default.
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('offers_bandwidth')->nullable()->default(null)->change();
        });
        // No admin has chosen a value yet — the column only held the old default.
        DB::table('products')->update(['offers_bandwidth' => null]);
    }

    public function down(): void
    {
        DB::table('products')->whereNull('offers_bandwidth')->update(['offers_bandwidth' => false]);
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('offers_bandwidth')->nullable(false)->default(false)->change();
        });
    }
};
