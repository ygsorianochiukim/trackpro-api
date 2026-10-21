<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the customer picks when adding a product to the cart.
        Schema::table('products', function (Blueprint $table) {
            $table->json('hardware_options')->nullable()->after('subscription'); // [{name, price}] — empty = base price only
            $table->json('add_on_slugs')->nullable()->after('hardware_options'); // add-ons offered with this product
            $table->boolean('offers_bandwidth')->default(false)->after('add_on_slugs'); // video bandwidth packages apply
            $table->boolean('requires_plan')->default(true)->after('offers_bandwidth'); // a platform subscription must be chosen
        });

        // Snapshot of the chosen options, and the plan's first period paid with the order.
        Schema::table('order_items', function (Blueprint $table) {
            $table->json('options')->nullable()->after('qty');
            $table->unsignedInteger('plan_total')->default(0)->after('line_total');
        });

        // `total` is what the customer pays at checkout: hardware (+ add-ons) + first plan period.
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('plan_total')->default(0)->after('subscription_total');
            $table->unsignedInteger('total')->default(0)->after('plan_total');
        });
        DB::table('orders')->update(['total' => DB::raw('hardware_subtotal')]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['plan_total', 'total']);
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['options', 'plan_total']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['hardware_options', 'add_on_slugs', 'offers_bandwidth', 'requires_plan']);
        });
    }
};
