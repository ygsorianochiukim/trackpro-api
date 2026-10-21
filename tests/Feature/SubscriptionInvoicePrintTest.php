<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionInvoicePrintTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_gets_a_single_invoice_with_billing_details(): void
    {
        $token = User::factory()->create()->createToken('admin', ['admin'])->plainTextToken;
        $customer = Customer::create([
            'name' => 'Juan Dela Cruz', 'email' => 'juan@example.com', 'phone' => '09171234567',
            'company' => 'JDC Logistics', 'password' => 'secret-password',
        ]);
        $subscription = Subscription::create([
            'reference' => 'SUB-000001', 'customer_id' => $customer->id, 'plan_name' => 'Standard',
            'billing_period' => 'monthly', 'quantity' => 3, 'unit_price' => 599, 'price' => 1797,
            'status' => 'active', 'starts_at' => '2026-10-01', 'renews_at' => '2026-11-01',
        ]);
        $invoice = SubscriptionInvoice::create([
            'reference' => 'INV-000001', 'subscription_id' => $subscription->id, 'customer_id' => $customer->id,
            'description' => 'Standard — 3 devices', 'amount' => 1797, 'period_start' => '2026-11-01',
            'period_end' => '2026-11-30', 'due_date' => '2026-11-01', 'status' => 'unpaid',
        ]);

        $this->withToken($token)->getJson("/api/admin/subscription-invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.reference', 'INV-000001')
            ->assertJsonPath('data.bill_to.company', 'JDC Logistics')
            ->assertJsonPath('data.bill_to.phone', '09171234567')
            ->assertJsonPath('data.quantity', 3)
            ->assertJsonPath('data.unit_price', 599)
            ->assertJsonPath('data.amount', 1797);
    }

    public function test_admin_can_customize_and_reset_the_printout(): void
    {
        $token = User::factory()->create()->createToken('admin', ['admin'])->plainTextToken;
        $customer = Customer::create(['name' => 'Juan', 'email' => 'juan@example.com', 'password' => 'secret-password']);
        $subscription = Subscription::create([
            'reference' => 'SUB-000002', 'customer_id' => $customer->id, 'plan_name' => 'Standard',
            'billing_period' => 'monthly', 'quantity' => 2, 'unit_price' => 599, 'price' => 1198,
            'status' => 'active', 'starts_at' => '2026-10-01', 'renews_at' => '2026-11-01',
        ]);
        $invoice = SubscriptionInvoice::create([
            'reference' => 'INV-000002', 'subscription_id' => $subscription->id, 'customer_id' => $customer->id,
            'description' => 'Standard — 2 devices (Nov 1 to Dec 1)', 'amount' => 1198, 'period_start' => '2026-11-01',
            'period_end' => '2026-12-01', 'due_date' => '2026-11-01', 'status' => 'unpaid',
        ]);
        $url = "/api/admin/subscription-invoices/{$invoice->id}";

        $this->withToken($token)->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.printout_edited', false)
            ->assertJsonPath('data.printout.number', 'INV-000002')
            ->assertJsonPath('data.printout.items.0.qty', 2)
            ->assertJsonPath('data.printout.items.0.unit_price', 599)
            ->assertJsonPath('data.printout.items.0.description', 'Standard — 2 devices · Service period Nov 1, 2026 – Nov 30, 2026');

        $this->withToken($token)->putJson("{$url}/printout", [
            'title' => 'Statement of Account',
            'number' => 'SOA-2026-11',
            'date' => '2026-10-20',
            'due_date' => '2026-11-05',
            'bill_to' => ['name' => 'Juan Dela Cruz', 'address' => 'Makati City'],
            'items' => [['description' => 'GPS tracking — November', 'qty' => 2, 'unit_price' => 599]],
            'notes' => 'Pay via bank transfer.',
        ])->assertOk()->assertJsonPath('data.title', 'Statement of Account')->assertJsonPath('data.notes_label', 'Notes');

        $this->withToken($token)->getJson($url)
            ->assertJsonPath('data.printout_edited', true)
            ->assertJsonPath('data.printout.number', 'SOA-2026-11')
            ->assertJsonPath('data.printout.bill_to.address', 'Makati City')
            ->assertJsonPath('data.amount', 1198);

        $this->withToken($token)->deleteJson("{$url}/printout")
            ->assertOk()
            ->assertJsonPath('edited', false)
            ->assertJsonPath('data.number', 'INV-000002');
        $this->assertNull($invoice->fresh()->printout);
    }

    public function test_customer_can_open_only_their_own_invoice(): void
    {
        $owner = Customer::create(['name' => 'Ana', 'email' => 'ana@example.com', 'company' => 'Ana Rentals', 'password' => 'secret-password']);
        $other = Customer::create(['name' => 'Ben', 'email' => 'ben@example.com', 'password' => 'secret-password']);
        $subscription = Subscription::create([
            'reference' => 'SUB-000009', 'customer_id' => $owner->id, 'plan_name' => 'Basic',
            'billing_period' => 'monthly', 'quantity' => 1, 'unit_price' => 299, 'price' => 299,
            'status' => 'active', 'starts_at' => '2026-10-01', 'renews_at' => '2026-12-01',
        ]);
        SubscriptionInvoice::create([
            'reference' => 'INV-000009', 'subscription_id' => $subscription->id, 'customer_id' => $owner->id, 'description' => 'Basic — monthly renewal',
            'amount' => 299, 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'due_date' => '2026-11-01',
            'status' => 'paid', 'payment_method' => 'gcash', 'paid_at' => now(),
        ]);

        $this->withToken($owner->createToken('customer')->plainTextToken)
            ->getJson('/api/customer/invoices/INV-000009')
            ->assertOk()
            ->assertJsonPath('data.reference', 'INV-000009')
            ->assertJsonPath('data.bill_to.company', 'Ana Rentals')
            ->assertJsonPath('data.quantity', 1)
            ->assertJsonPath('data.unit_price', 299);

        $this->app['auth']->forgetGuards();
        $this->withToken($other->createToken('customer')->plainTextToken)
            ->getJson('/api/customer/invoices/INV-000009')
            ->assertNotFound();
    }

    public function test_requires_admin(): void
    {
        $this->getJson('/api/admin/subscription-invoices/1')->assertUnauthorized();
    }
}
