<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function token(): string
    {
        return User::factory()->create()->createToken('admin', ['admin'])->plainTextToken;
    }

    /** What the template editor sends: a whole sample invoice with the template parts set. */
    private function template(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Sales Invoice',
            'number' => 'SAMPLE',
            'date' => '2026-10-20',
            'bill_to' => ['name' => 'Sample customer'],
            'items' => [['description' => 'Sample item', 'qty' => 1, 'unit_price' => 1000]],
            'from' => ['name' => 'TrackPro GPS', 'lines' => "Makati City\nVAT Reg. TIN 000-111-222"],
            'footer' => 'Thank you for your business.',
            'notes_label' => 'Terms',
            'notes' => 'Net 15.',
            'bill_to_fields' => [['label' => 'TIN', 'value' => '']],
            'details' => [['label' => 'Terms', 'value' => 'Net 15']],
            'sections' => [['label' => 'Bank details', 'body' => 'BDO 0012-3456-7890']],
        ], $overrides);
    }

    public function test_template_saves_only_its_own_parts(): void
    {
        $token = $this->token();

        $this->withToken($token)->getJson('/api/admin/invoice-template')
            ->assertOk()
            ->assertJsonPath('data.title', 'Invoice')
            ->assertJsonPath('data.from', null);

        $this->withToken($token)->putJson('/api/admin/invoice-template', $this->template())
            ->assertOk()
            ->assertJsonPath('data.title', 'Sales Invoice')
            ->assertJsonPath('data.bill_to_fields.0.label', 'TIN')
            ->assertJsonMissingPath('data.number')
            ->assertJsonMissingPath('data.items');
    }

    public function test_new_invoices_start_from_the_template_and_saved_ones_keep_theirs(): void
    {
        $token = $this->token();
        $this->withToken($token)->putJson('/api/admin/invoice-template', $this->template());

        $blank = $this->withToken($token)->getJson('/api/admin/custom-invoices/new')
            ->assertJsonPath('data.title', 'Sales Invoice')
            ->assertJsonPath('data.sections.0.label', 'Bank details')
            ->assertJsonPath('data.footer', 'Thank you for your business.')
            ->json('data');

        $blank['bill_to']['name'] = 'Maria Santos';
        $blank['items'][0]['description'] = 'GPS installation';
        $id = $this->withToken($token)->postJson('/api/admin/custom-invoices', $blank)->assertCreated()->json('id');

        // A later template change applies to new invoices only.
        $this->withToken($token)->putJson('/api/admin/invoice-template', $this->template(['title' => 'Billing Statement']));

        $this->withToken($token)->getJson("/api/admin/custom-invoices/{$id}")->assertJsonPath('data.title', 'Sales Invoice');
        $this->withToken($token)->getJson('/api/admin/custom-invoices/new')->assertJsonPath('data.title', 'Billing Statement');
    }

    public function test_customer_gets_their_renewal_invoice_on_the_template_with_admin_edits(): void
    {
        $token = $this->token();
        $this->withToken($token)->putJson('/api/admin/invoice-template', $this->template());

        $customer = Customer::create(['name' => 'Ana', 'email' => 'ana@example.com', 'company' => 'Ana Rentals', 'password' => 'secret-password']);
        $subscription = Subscription::create([
            'reference' => 'SUB-000009', 'customer_id' => $customer->id, 'plan_name' => 'Basic',
            'billing_period' => 'monthly', 'quantity' => 1, 'unit_price' => 299, 'price' => 299,
            'status' => 'active', 'starts_at' => '2026-10-01', 'renews_at' => '2026-12-01',
        ]);
        $invoice = SubscriptionInvoice::create([
            'reference' => 'INV-000009', 'subscription_id' => $subscription->id, 'customer_id' => $customer->id,
            'description' => 'Basic — monthly renewal', 'amount' => 299, 'period_start' => '2026-11-01',
            'period_end' => '2026-12-01', 'due_date' => '2026-11-01', 'status' => 'unpaid',
        ]);
        $customerToken = $customer->createToken('customer')->plainTextToken;
        $this->app['auth']->forgetGuards();

        $this->withToken($customerToken)->getJson('/api/customer/invoices/INV-000009')
            ->assertOk()
            ->assertJsonPath('data.printout.title', 'Sales Invoice')
            ->assertJsonPath('data.printout.number', 'INV-000009')
            ->assertJsonPath('data.printout.bill_to.company', 'Ana Rentals')
            ->assertJsonPath('data.printout.notes_label', 'Payment terms')
            ->assertJsonPath('data.printout.sections.0.body', 'BDO 0012-3456-7890');

        $this->app['auth']->forgetGuards();
        $printout = $this->withToken($token)->getJson("/api/admin/subscription-invoices/{$invoice->id}")->json('data.printout');
        $printout['bill_to_fields'] = [['label' => 'TIN', 'value' => '123-456-789']];
        $this->withToken($token)->putJson("/api/admin/subscription-invoices/{$invoice->id}/printout", $printout)->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($customerToken)->getJson('/api/customer/invoices/INV-000009')
            ->assertJsonPath('data.printout.bill_to_fields.0.value', '123-456-789')
            ->assertJsonPath('data.amount', 299);
    }
}
