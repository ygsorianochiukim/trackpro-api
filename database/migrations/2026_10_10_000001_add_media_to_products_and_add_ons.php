<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Photos and short video clips uploaded from the admin, shown on the
     * storefront cards. Each entry is {type: image|video, path} on the public disk.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('media')->nullable()->after('highlights');
        });

        Schema::table('add_ons', function (Blueprint $table) {
            $table->json('media')->nullable()->after('devices');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('media');
        });

        Schema::table('add_ons', function (Blueprint $table) {
            $table->dropColumn('media');
        });
    }
};
