<?php

namespace Tests\Feature;

use App\Models\AddOn;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every admin-managed record (products, add-ons, subscriptions, expenses) is fully editable. */
class AdminEditingTest extends TestCase
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

    public function test_every_product_field_is_editable(): void
    {
        $product = Product::create([
            'slug' => 'old', 'name' => 'Old', 'model' => 'M1', 'category' => 'Vehicle', 'price' => 100,
            'subscription' => 10, 'tagline' => 'Old tagline', 'highlights' => ['A'], 'stock' => 1,
            'featured' => false, 'is_active' => true,
        ]);
        Product::create([
            'slug' => 'taken', 'name' => 'Taken', 'model' => 'M2', 'category' => 'Vehicle', 'price' => 1,
            'subscription' => 0, 'tagline' => 't', 'highlights' => [], 'stock' => 0, 'featured' => false, 'is_active' => true,
        ]);

        $this->api()->patchJson("/api/admin/products/{$product->id}", [
            'name' => 'New Name', 'slug' => '', 'model' => 'M9', 'category' => 'Asset', 'icon' => 'map',
            'tagline' => 'New tagline', 'description' => 'Desc', 'highlights' => [' X ', '', 'Y'],
            'price' => 200, 'subscription' => 20, 'stock' => 5, 'featured' => true, 'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('data.slug', 'new-name')
            ->assertJsonPath('data.model', 'M9')
            ->assertJsonPath('data.tagline', 'New tagline')
            ->assertJsonPath('data.highlights', ['X', 'Y'])
            ->assertJsonPath('data.price', 200);

        // A slug already used by another product is refused.
        $this->api()->patchJson("/api/admin/products/{$product->id}", ['slug' => 'taken'])
            ->assertStatus(422)->assertJsonValidationErrors('slug');

        // Partial updates leave other fields alone.
        $this->api()->patchJson("/api/admin/products/{$product->id}", ['stock' => 9])
            ->assertOk()->assertJsonPath('data.name', 'New Name')->assertJsonPath('data.stock', 9);
    }

    public function test_add_on_slug_is_editable(): void
    {
        $addOn = AddOn::create(['slug' => 'test-sensor', 'name' => 'Test Sensor', 'price' => 100, 'sort_order' => 1, 'is_active' => true]);

        $this->api()->patchJson("/api/admin/add-ons/{$addOn->id}", ['name' => 'Test Sensor', 'slug' => 'Test Probe'])
            ->assertOk()->assertJsonPath('data.slug', 'test-probe');

        // Saving with its own slug doesn't append a suffix.
        $this->api()->patchJson("/api/admin/add-ons/{$addOn->id}", ['slug' => 'test-probe'])
            ->assertOk()->assertJsonPath('data.slug', 'test-probe');
    }

    public function test_every_subscription_field_is_editable(): void
    {
        $alice = Customer::create(['name' => 'Alice', 'email' => 'a@example.com', 'password' => 'secret123']);
        $bob = Customer::create(['name' => 'Bob', 'email' => 'b@example.com', 'password' => 'secret123']);
        $plan = SubscriptionPlan::create(['slug' => 'pro', 'name' => 'Pro', 'price' => 3000, 'billing_period' => 'yearly', 'is_active' => true]);

        $sub = Subscription::create([
            'reference' => 'SUB-000001', 'customer_id' => $alice->id, 'plan_name' => 'Custom',
            'billing_period' => 'yearly', 'quantity' => 1, 'unit_price' => 1000, 'price' => 1000,
            'status' => 'active', 'starts_at' => '2026-01-01', 'renews_at' => '2027-01-01', 'auto_renew' => true,
        ]);

        $this->api()->patchJson("/api/admin/subscriptions/{$sub->id}", [
            'customer_id' => $bob->id, 'subscription_plan_id' => $plan->id, 'quantity' => 3, 'unit_price' => 2500,
            'billing_period' => 'monthly', 'starts_at' => '2026-02-01', 'renews_at' => '2026-03-01',
            'auto_renew' => false, 'notes' => 'Negotiated',
        ])->assertOk()
            ->assertJsonPath('data.customer.name', 'Bob')
            ->assertJsonPath('data.subscription_plan_id', $plan->id)
            ->assertJsonPath('data.plan_name', 'Pro')
            ->assertJsonPath('data.price', 7500)
            ->assertJsonPath('data.billing_period', 'monthly')
            ->assertJsonPath('data.starts_at', '2026-02-01')
            ->assertJsonPath('data.auto_renew', false);

        // Renewal before the start is refused.
        $this->api()->patchJson("/api/admin/subscriptions/{$sub->id}", ['renews_at' => '2026-01-15'])
            ->assertStatus(422);

        // Unlinking the plan makes it custom; a status-only change still works.
        $this->api()->patchJson("/api/admin/subscriptions/{$sub->id}", ['subscription_plan_id' => null, 'plan_name' => 'Fleet deal'])
            ->assertOk()->assertJsonPath('data.subscription_plan_id', null)->assertJsonPath('data.plan_name', 'Fleet deal');
        $this->api()->patchJson("/api/admin/subscriptions/{$sub->id}", ['status' => 'cancelled'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.plan_name', 'Fleet deal');
    }

    public function test_expenses_can_be_edited_and_totals_follow(): void
    {
        $rent = ExpenseCategory::where('name', 'Rent')->firstOrFail();
        $gcash = Wallet::create(['name' => 'GCash', 'type' => 'ewallet', 'is_active' => true]);
        $today = now('Asia/Manila')->toDateString();

        $id = $this->api()->postJson('/api/admin/expenses', [
            'title' => 'Rent', 'expense_category_id' => $rent->id, 'amount' => 5000, 'incurred_on' => $today,
        ])->assertCreated()->json('data.id');

        $this->api()->getJson('/api/admin/expenses')
            ->assertJsonPath('cashflow.today.expenses', 5000)
            ->assertJsonPath('data.0.incurred_on', $today);

        $this->api()->patchJson("/api/admin/expenses/{$id}", [
            'title' => 'Rent (corrected)', 'expense_category_id' => $rent->id, 'wallet_id' => $gcash->id,
            'amount' => 4500, 'incurred_on' => $today,
        ])->assertOk()->assertJsonPath('data.amount', 4500);

        $this->api()->getJson('/api/admin/expenses')->assertJsonPath('cashflow.today.expenses', 4500);

        $balances = collect($this->api()->getJson('/api/admin/wallets')->json('data'))->keyBy('name');
        $this->assertSame(-4500, $balances['GCash']['balance']);
        $this->assertSame(0, $balances['Cash on Hand']['balance']);

        // A ₱0 expense is rejected.
        $this->api()->patchJson("/api/admin/expenses/{$id}", [
            'title' => 'x', 'expense_category_id' => $rent->id, 'amount' => 0, 'incurred_on' => $today,
        ])->assertStatus(422)->assertJsonValidationErrors('amount');

        // Deactivating a wallet keeps its money in the overall total.
        $gcash->update(['is_active' => false]);
        $this->api()->getJson('/api/admin/wallets')->assertJsonPath('total', -4500);

        // The expense can still be edited while keeping its (now inactive) wallet.
        $this->api()->patchJson("/api/admin/expenses/{$id}", [
            'title' => 'Rent', 'expense_category_id' => $rent->id, 'wallet_id' => $gcash->id, 'amount' => 4000, 'incurred_on' => $today,
        ])->assertOk();
        $this->assertSame(4000, Expense::find($id)->amount);
    }
}
