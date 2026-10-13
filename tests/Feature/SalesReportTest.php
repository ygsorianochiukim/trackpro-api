<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Paid subscription renewals count as sales alongside order payments. */
class SalesReportTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = User::factory()->create()->createToken('admin', ['admin'])->plainTextToken;

        $order = Order::create([
            'reference' => 'TP-000001', 'customer_name' => 'Juan Dela Cruz',
            'customer_email' => 'juan@example.com', 'customer_phone' => '09171234567',
            'delivery_address' => 'Koronadal City', 'hardware_subtotal' => 3000,
            'subscription_total' => 0, 'status' => 'paid', 'payment_status' => 'paid', 'paid_at' => now(),
        ]);
        Payment::create(['order_id' => $order->id, 'provider' => 'manual', 'amount' => 3000, 'status' => 'paid', 'paid_at' => now()->subHour()]);

        $customer = Customer::create(['name' => 'Ana Reyes', 'email' => 'ana@example.com', 'password' => 'secret-password']);
        $subscription = Subscription::create([
            'reference' => 'SUB-000001', 'customer_id' => $customer->id, 'plan_name' => 'Basic',
            'billing_period' => 'monthly', 'quantity' => 1, 'unit_price' => 1, 'price' => 1,
            'status' => 'active', 'starts_at' => today(), 'renews_at' => today()->addMonth(),
        ]);
        $base = [
            'subscription_id' => $subscription->id, 'customer_id' => $customer->id, 'description' => 'Basic — monthly renewal',
            'amount' => 1, 'period_start' => today(), 'period_end' => today()->addMonth(), 'due_date' => today(),
        ];
        SubscriptionInvoice::create($base + ['reference' => 'INV-000004', 'status' => 'paid', 'payment_method' => 'qrph', 'paid_at' => now()]);
        SubscriptionInvoice::create(['reference' => 'INV-000005', 'status' => 'unpaid', 'period_start' => today()->addMonth(), 'period_end' => today()->addMonths(2)] + $base); // not a sale
    }

    public function test_totals_include_paid_renewals(): void
    {
        $this->withToken($this->token)->getJson('/api/admin/reports/sales')
            ->assertOk()
            ->assertJsonPath('totals.today', 3001)
            ->assertJsonPath('totals.all_time', 3001);
    }

    public function test_sales_details_list_both_kinds_newest_first(): void
    {
        $res = $this->withToken($this->token)->getJson('/api/admin/reports/transactions')
            ->assertOk()
            ->assertJsonPath('totals.amount', 3001)
            ->assertJsonPath('totals.count', 2);

        $this->assertSame('subscription', $res->json('data.0.type'));
        $this->assertSame('INV-000004', $res->json('data.0.renewal.reference'));
        $this->assertSame('Ana Reyes', $res->json('data.0.customer.name'));
        $this->assertSame('qrph', $res->json('data.0.method'));
        $this->assertSame('order', $res->json('data.1.type'));
        $this->assertSame('TP-000001', $res->json('data.1.order.reference'));
    }

    public function test_search_finds_renewals_by_customer_and_reference(): void
    {
        foreach (['Ana', 'INV-000004', 'SUB-000001'] as $q) {
            $this->withToken($this->token)->getJson('/api/admin/reports/transactions?q='.$q)
                ->assertJsonPath('totals.count', 1)
                ->assertJsonPath('data.0.type', 'subscription');
        }
    }

    public function test_today_log_lists_who_bought_what(): void
    {
        $res = $this->withToken($this->token)->getJson('/api/admin/reports/today')
            ->assertOk()
            ->assertJsonPath('totals.amount', 3001)
            ->assertJsonPath('totals.count', 2)
            ->assertJsonPath('totals.orders', 1)
            ->assertJsonPath('totals.renewals', 1);

        $this->assertSame(['Ana Reyes', 'Juan Dela Cruz'], array_column($res->json('data.*.customer'), 'name'));

        $this->withToken($this->token)->getJson('/api/admin/reports/today?date=2020-01-01')
            ->assertJsonPath('totals.count', 0);
    }

    public function test_days_follow_philippine_time(): void
    {
        // 7:30 AM Oct 13 in Manila is still Oct 12 in UTC.
        $this->travelTo(Carbon::parse('2026-10-12 23:30:00', 'UTC'));
        Payment::query()->update(['paid_at' => Carbon::parse('2026-10-12 23:00:00', 'UTC')]); // 7:00 AM Manila, Oct 13
        SubscriptionInvoice::where('reference', 'INV-000004')->update(['paid_at' => Carbon::parse('2026-10-12 15:00:00', 'UTC')]); // 11 PM Manila, Oct 12

        $this->withToken($this->token)->getJson('/api/admin/reports/today')
            ->assertJsonPath('date', '2026-10-13')
            ->assertJsonPath('totals.amount', 3000);
        $this->withToken($this->token)->getJson('/api/admin/reports/sales')
            ->assertJsonPath('totals.today', 3000);
        $this->withToken($this->token)->getJson('/api/admin/reports/transactions?from=2026-10-12&to=2026-10-12')
            ->assertJsonPath('totals.amount', 1);
    }

    public function test_dashboard_and_cashflow_count_renewals(): void
    {
        $this->withToken($this->token)->getJson('/api/admin/dashboard')
            ->assertJsonPath('stats.revenue_total', 3001)
            ->assertJsonPath('stats.revenue_today', 3001);

        $this->withToken($this->token)->getJson('/api/admin/expenses')
            ->assertJsonPath('cashflow.today.income', 3001);
    }
}
