<?php

use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes `subscription_plans` the single source of truth for plans: the homepage
 * Subscription Plan section (previously hard-coded in the frontend), the
 * customer account sign-up, and billing all read the same rows.
 *
 * Adds the homepage-only fields, then seeds the homepage lineup (Basic /
 * Standard / Premium / Enterprise) and retires the old yearly starter plans
 * from sale. Existing subscriptions are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->string('fleet_size')->nullable()->after('description');
            $table->json('recommended_for')->nullable()->after('features'); // string[]
            $table->json('comparison')->nullable()->after('recommended_for'); // {feature, value}[]
            $table->boolean('is_popular')->default(false)->after('comparison');
            $table->unsignedInteger('sort_order')->default(0)->after('is_popular');
        });

        (new SubscriptionPlanSeeder())->run();
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn(['fleet_size', 'recommended_for', 'comparison', 'is_popular', 'sort_order']);
        });
    }
};
