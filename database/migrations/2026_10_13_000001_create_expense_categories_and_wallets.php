<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Managed expense categories (replacing the fixed list) and wallets — the
 * money accounts (cash, GCash, bank…) expenses are paid from and sales land in.
 */
return new class extends Migration
{
    /** The categories the expense form offered before they became editable. */
    private const DEFAULT_CATEGORIES = ['Inventory', 'Salaries', 'Rent', 'Utilities', 'Marketing', 'Logistics', 'Other'];

    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            // Expenses in this category must say what they were and the invoice / OR behind them.
            $table->boolean('requires_reference')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('type', 20)->default('cash'); // cash, ewallet, bank, other
            $table->bigInteger('opening_balance')->default(0); // whole pesos
            $table->boolean('is_default')->default(false); // receives sales income
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('wallet_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->foreignId('to_wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->unsignedInteger('amount');
            $table->date('transferred_on');
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->index('transferred_on');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('expense_category_id')->nullable()->after('category')->constrained()->nullOnDelete();
            // Null = the default wallet (expenses recorded before wallets existed).
            $table->foreignId('wallet_id')->nullable()->after('expense_category_id')->constrained()->nullOnDelete();
        });

        // Seed the categories (plus any free-text ones already used) and link existing expenses.
        $names = collect(self::DEFAULT_CATEGORIES)
            ->merge(DB::table('expenses')->distinct()->pluck('category'))
            ->map(fn ($n) => trim((string) $n))
            ->filter()
            ->unique(fn ($n) => mb_strtolower($n))
            ->values();
        $now = now();
        foreach ($names as $i => $name) {
            $id = DB::table('expense_categories')->insertGetId([
                'name' => $name,
                'requires_reference' => $name === 'Other',
                'sort_order' => $i + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('expenses')->whereRaw('LOWER(category) = ?', [mb_strtolower($name)])->update(['expense_category_id' => $id]);
        }

        DB::table('wallets')->insert([
            'name' => 'Cash on Hand', 'type' => 'cash', 'opening_balance' => 0,
            'is_default' => true, 'is_active' => true, 'sort_order' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wallet_id');
            $table->dropConstrainedForeignId('expense_category_id');
        });
        Schema::dropIfExists('wallet_transfers');
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('expense_categories');
    }
};
