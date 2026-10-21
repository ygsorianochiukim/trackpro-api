<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // This product's own video bandwidth packages [{slug, name, description, price}];
        // empty = the default packages (Add-ons → Video Bandwidth).
        Schema::table('products', function (Blueprint $table) {
            $table->json('bandwidth_options')->nullable()->after('offers_bandwidth');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('bandwidth_options');
        });
    }
};
