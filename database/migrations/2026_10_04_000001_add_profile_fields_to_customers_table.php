<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('company')->nullable()->after('phone');
            $table->string('fleet_size', 32)->nullable()->after('company');
            $table->text('vehicle_info')->nullable()->after('fleet_size');
            // When the customer ticked "I agree to the Privacy Policy, Terms of
            // Service and Service Agreement" at signup.
            $table->timestamp('terms_accepted_at')->nullable()->after('vehicle_info');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['company', 'fleet_size', 'vehicle_info', 'terms_accepted_at']);
        });
    }
};
