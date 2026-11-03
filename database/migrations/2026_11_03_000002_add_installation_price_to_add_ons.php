<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Optional one-time installation fee per add-on. Null / 0 = installation
        // isn't offered, so the "include installation?" prompt is skipped.
        Schema::table('add_ons', function (Blueprint $table) {
            $table->unsignedInteger('installation_price')->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('add_ons', function (Blueprint $table) {
            $table->dropColumn('installation_price');
        });
    }
};
