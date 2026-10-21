<?php

namespace Tests\Feature;

use App\Models\CustomInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomInvoicesTest extends TestCase
{
    use RefreshDatabase;

    private function token(): string
    {
        return User::factory()->create()->createToken('admin', ['admin'])->plainTextToken;
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'title' => 'Invoice',
            'number' => 'CI-000001',
            'date' => '2026-10-20',
            'bill_to' => ['name' => 'Maria Santos', 'company' => 'Santos Hauling'],
            'items' => [
                ['description' => 'GPS installation', 'qty' => 3, 'unit_price' => 1500],
                ['description' => 'Site visit', 'qty' => 1, 'unit_price' => 800],
            ],
            'discount' => 300,
            'notes' => 'Pay within 15 days.',
        ], $overrides);
    }

    public function test_admin_creates_lists_updates_and_deletes_an_invoice(): void
    {
        $token = $this->token();

        $this->withToken($token)->getJson('/api/admin/custom-invoices/new')
            ->assertOk()
            ->assertJsonPath('data.number', 'CI-000001')
            ->assertJsonPath('data.items.0.qty', 1);

        $id = $this->withToken($token)->postJson('/api/admin/custom-invoices', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.notes_label', 'Notes')
            ->json('id');

        $this->assertSame(5000, CustomInvoice::find($id)->total);

        $this->withToken($token)->getJson('/api/admin/custom-invoices?search=santos')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.customer_name', 'Maria Santos')
            ->assertJsonPath('data.0.company', 'Santos Hauling')
            ->assertJsonPath('data.0.total', 5000);

        $this->withToken($token)->putJson("/api/admin/custom-invoices/{$id}", $this->payload([
            'title' => 'Statement of Account', 'discount' => 0,
        ]))->assertOk()->assertJsonPath('data.title', 'Statement of Account');
        $this->assertSame(5300, CustomInvoice::find($id)->total);

        $this->withToken($token)->getJson('/api/admin/custom-invoices/new')->assertJsonPath('data.number', 'CI-000002');

        $this->withToken($token)->deleteJson("/api/admin/custom-invoices/{$id}")->assertOk();
        $this->assertDatabaseCount('custom_invoices', 0);
    }

    public function test_admin_can_add_their_own_fields(): void
    {
        $token = $this->token();

        $id = $this->withToken($token)->postJson("/api/admin/custom-invoices", $this->payload([
            "bill_to_fields" => [["label" => "TIN", "value" => "123-456-789"], ["label" => "", "value" => " "]],
            "details" => [["label" => "PO number", "value" => "PO-88"], ["label" => "Terms", "value" => "Net 15"]],
            "sections" => [["label" => "Bank details", "body" => "BDO 0012-3456-7890"]],
            "from" => ["name" => "TrackPro GPS", "lines" => "Makati City"],
            "footer" => "Thank you!",
        ]))->assertCreated()->json("id");

        $this->withToken($token)->getJson("/api/admin/custom-invoices/{$id}")
            ->assertJsonCount(1, "data.bill_to_fields")
            ->assertJsonPath("data.bill_to_fields.0.value", "123-456-789")
            ->assertJsonPath("data.details.1.label", "Terms")
            ->assertJsonPath("data.sections.0.body", "BDO 0012-3456-7890")
            ->assertJsonPath("data.from.lines", "Makati City")
            ->assertJsonPath("data.footer", "Thank you!");
    }

    public function test_validates_the_invoice(): void
    {
        $this->withToken($this->token())->postJson('/api/admin/custom-invoices', $this->payload(['bill_to' => ['name' => '']]))
            ->assertUnprocessable();
    }

    public function test_requires_admin(): void
    {
        $this->getJson('/api/admin/custom-invoices')->assertUnauthorized();
    }
}
