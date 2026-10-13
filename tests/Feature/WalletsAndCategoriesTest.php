<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletsAndCategoriesTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = User::factory()->create()->createToken('admin', ['admin'])->plainTextToken;
    }

    private function api(): static
    {
        return $this->withToken($this->token);
    }

    private function category(string $name): ExpenseCategory
    {
        return ExpenseCategory::where('name', $name)->firstOrFail();
    }

    public function test_migration_seeds_categories_and_a_default_wallet(): void
    {
        $this->api()->getJson('/api/admin/expense-categories')
            ->assertOk()
            ->assertJsonCount(7, 'data')
            ->assertJsonPath('data.0.name', 'Inventory');
        $this->assertTrue($this->category('Other')->requires_reference);
        $this->assertSame('Cash on Hand', Wallet::default()->name);
    }

    public function test_category_rules_rename_and_delete_with_move(): void
    {
        $rent = $this->category('Rent');
        $other = $this->category('Other');

        // A category that requires a reference rejects an expense without one…
        $this->api()->postJson('/api/admin/expenses', [
            'title' => 'Misc', 'expense_category_id' => $other->id, 'amount' => 100, 'incurred_on' => '2026-10-10',
        ])->assertStatus(422)->assertJsonValidationErrors(['invoice_reference', 'notes']);

        // …a normal one doesn't, and the expense lands in the default wallet.
        $this->api()->postJson('/api/admin/expenses', [
            'title' => 'October rent', 'expense_category_id' => $rent->id, 'wallet_id' => null, 'amount' => 5000, 'incurred_on' => '2026-10-10',
        ])->assertCreated()->assertJsonPath('data.category', 'Rent')->assertJsonPath('data.wallet_id', Wallet::default()->id);

        // Renaming carries over to its expenses.
        $this->api()->patchJson("/api/admin/expense-categories/{$rent->id}", ['name' => 'Office Rent'])->assertOk();
        $this->assertSame('Office Rent', Expense::first()->category);

        // Duplicate names are refused.
        $this->api()->postJson('/api/admin/expense-categories', ['name' => 'Office Rent'])->assertStatus(422);

        // Deleting a category in use needs a target, then moves its expenses.
        $this->api()->deleteJson("/api/admin/expense-categories/{$rent->id}")->assertStatus(422)->assertJsonValidationErrors('move_to');
        $utilities = $this->category('Utilities');
        $this->api()->deleteJson("/api/admin/expense-categories/{$rent->id}?move_to={$utilities->id}")->assertOk();
        $this->assertSame(['Utilities', $utilities->id], [Expense::first()->category, Expense::first()->expense_category_id]);

        // An unused category just goes.
        $this->api()->postJson('/api/admin/expense-categories', ['name' => 'Fuel', 'requires_reference' => true])->assertCreated();
        $this->api()->deleteJson('/api/admin/expense-categories/'.$this->category('Fuel')->id)->assertOk();
    }

    public function test_wallet_balances_follow_sales_expenses_and_transfers(): void
    {
        $cash = Wallet::default();
        $cash->update(['opening_balance' => 1000]);
        $gcash = Wallet::create(['name' => 'GCash', 'type' => 'ewallet', 'opening_balance' => 200]);

        // A paid renewal (₱500) is sales income → default wallet.
        $customer = Customer::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'secret-password']);
        $sub = Subscription::create([
            'reference' => 'SUB-1', 'customer_id' => $customer->id, 'plan_name' => 'Basic', 'billing_period' => 'monthly',
            'quantity' => 1, 'unit_price' => 500, 'price' => 500, 'status' => 'active', 'starts_at' => today(), 'renews_at' => today()->addMonth(),
        ]);
        SubscriptionInvoice::create([
            'reference' => 'INV-1', 'subscription_id' => $sub->id, 'customer_id' => $customer->id, 'description' => 'Basic',
            'amount' => 500, 'period_start' => today(), 'period_end' => today()->addMonth(), 'due_date' => today(),
            'status' => 'paid', 'paid_at' => now(),
        ]);

        // Expenses: ₱300 from cash, ₱50 from GCash, and a legacy one with no wallet (counts as cash).
        $inventory = $this->category('Inventory');
        foreach ([[300, $cash->id], [50, $gcash->id], [25, null]] as [$amount, $wallet]) {
            Expense::create(['title' => 'x', 'category' => 'Inventory', 'expense_category_id' => $inventory->id,
                'wallet_id' => $wallet, 'amount' => $amount, 'incurred_on' => today()]);
        }

        // Transfer ₱400 cash → GCash.
        $this->api()->postJson('/api/admin/wallet-transfers', [
            'from_wallet_id' => $cash->id, 'to_wallet_id' => $gcash->id, 'amount' => 400, 'transferred_on' => today()->toDateString(),
        ])->assertCreated();

        $res = $this->api()->getJson('/api/admin/wallets')->assertOk();
        $byName = collect($res->json('data'))->keyBy('name');

        // Cash: 1000 + 500 sales − 300 − 25 − 400 = 775. GCash: 200 − 50 + 400 = 550.
        $this->assertSame(775, $byName['Cash on Hand']['balance']);
        $this->assertSame(550, $byName['GCash']['balance']);
        $this->assertSame(1325, $res->json('total'));
        $this->assertSame(['in' => 500, 'out' => 725], $byName['Cash on Hand']['month']);
        $this->assertSame('Cash on Hand', $res->json('transfers.0.from.name'));

        // Same wallet on both ends is refused.
        $this->api()->postJson('/api/admin/wallet-transfers', [
            'from_wallet_id' => $cash->id, 'to_wallet_id' => $cash->id, 'amount' => 1, 'transferred_on' => today()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_default_wallet_rules_and_deactivating_used_wallets(): void
    {
        $cash = Wallet::default();
        $bank = $this->api()->postJson('/api/admin/wallets', ['name' => 'BDO', 'type' => 'bank'])->assertCreated()->json('data');

        // The default can't be removed or switched off directly.
        $this->api()->deleteJson("/api/admin/wallets/{$cash->id}")->assertStatus(422);
        $this->api()->patchJson("/api/admin/wallets/{$cash->id}", ['is_active' => false])->assertStatus(422);

        // Making BDO the default moves the flag.
        $this->api()->patchJson("/api/admin/wallets/{$bank['id']}", ['is_default' => true])->assertOk();
        $this->assertSame('BDO', Wallet::default()->name);
        $this->assertSame(1, Wallet::where('is_default', true)->count());

        // Cash has history (an expense) → deactivated, not deleted.
        Expense::create(['title' => 'x', 'category' => 'Rent', 'wallet_id' => $cash->id, 'amount' => 10, 'incurred_on' => today()]);
        $this->api()->deleteJson("/api/admin/wallets/{$cash->id}")->assertOk();
        $this->assertFalse($cash->fresh()->is_active);

        // Inactive wallets can't take new expenses.
        $this->api()->postJson('/api/admin/expenses', [
            'title' => 'y', 'expense_category_id' => $this->category('Rent')->id, 'wallet_id' => $cash->id, 'amount' => 5, 'incurred_on' => '2026-10-10',
        ])->assertStatus(422)->assertJsonValidationErrors('wallet_id');

        // The expense form gets categories + active wallets (default first).
        $this->api()->getJson('/api/admin/expenses')
            ->assertJsonPath('options.wallets.0.name', 'BDO')
            ->assertJsonCount(1, 'options.wallets')
            ->assertJsonPath('data.0.wallet_name', 'Cash on Hand');
    }
}
