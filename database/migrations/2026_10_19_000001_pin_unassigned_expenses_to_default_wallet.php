<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Expenses recorded before wallets existed have no wallet and were counted
 * against whichever wallet is the default at the time — so switching the
 * default moved them. Pin them to the current default wallet for good.
 */
return new class extends Migration
{
    public function up(): void
    {
        $default = DB::table('wallets')->where('is_default', true)->value('id');

        if ($default) {
            DB::table('expenses')->whereNull('wallet_id')->update(['wallet_id' => $default]);
        }
    }

    public function down(): void
    {
        // Nothing to undo — the wallet a legacy expense was paid from can't be told apart afterwards.
    }
};
