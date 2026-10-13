<?php

namespace Tests\Feature;

use App\Models\AddOn;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminChangesTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = User::factory()->create()->createToken('admin', ['admin'])->plainTextToken;
    }

    private function product(): Product
    {
        return Product::create([
            'slug' => 'test-unit', 'name' => 'Test Unit', 'model' => 'GPS Tracker',
            'category' => 'Vehicle', 'price' => 3000, 'subscription' => 300, 'tagline' => 'Tracker',
            'highlights' => [], 'featured' => false, 'stock' => 5, 'is_active' => true,
        ]);
    }

    private function paidOrder(): Order
    {
        $product = $this->product();
        $order = Order::create([
            'reference' => 'TP-000001', 'customer_name' => 'Juan Dela Cruz',
            'customer_email' => 'juan@example.com', 'customer_phone' => '09171234567',
            'delivery_address' => 'Koronadal City', 'hardware_subtotal' => 6000,
            'subscription_total' => 0, 'status' => 'paid', 'payment_status' => 'paid', 'paid_at' => now(),
        ]);
        $order->items()->create([
            'product_id' => $product->id, 'product_name' => 'TrackPro Core', 'product_model' => 'GPS Tracker',
            'unit_price' => 3000, 'qty' => 2, 'line_total' => 6000,
        ]);
        Payment::create(['order_id' => $order->id, 'provider' => 'manual', 'amount' => 6000, 'status' => 'paid', 'paid_at' => now()]);

        return $order;
    }

    public function test_media_upload_attach_and_cleanup(): void
    {
        Storage::fake('public');
        $product = $this->product();

        $photo = $this->withToken($this->token)
            ->post('/api/admin/media', ['file' => UploadedFile::fake()->image('unit.jpg')], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'image')
            ->json('data');
        Storage::disk('public')->assertExists($photo['path']);

        $this->withToken($this->token)->patchJson("/api/admin/products/{$product->id}", [
            'price' => 3000, 'subscription' => 300, 'stock' => 5, 'is_active' => true, 'featured' => false,
            'media' => [['type' => 'image', 'path' => $photo['path']]],
        ])->assertOk()->assertJsonPath('data.media.0.path', $photo['path']);

        $this->getJson('/api/products/test-unit')->assertJsonPath('data.media.0.url', Storage::disk('public')->url($photo['path']));

        // Removing it from the list deletes the file.
        $this->withToken($this->token)->patchJson("/api/admin/products/{$product->id}", [
            'price' => 3000, 'subscription' => 300, 'stock' => 5, 'is_active' => true, 'featured' => false, 'media' => [],
        ])->assertOk();
        Storage::disk('public')->assertMissing($photo['path']);
    }

    public function test_media_rejects_paths_outside_the_media_folder_and_bad_files(): void
    {
        Storage::fake('public');
        $before = AddOn::count();

        $this->withToken($this->token)->postJson('/api/admin/add-ons', [
            'name' => 'Fuel Sensor', 'price' => 100, 'media' => [['type' => 'image', 'path' => '../.env']],
        ])->assertStatus(422)->assertJsonValidationErrors('media.0.path');

        $this->withToken($this->token)
            ->post('/api/admin/media', ['file' => UploadedFile::fake()->create('x.exe', 10)], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertSame($before, AddOn::count());
    }

    public function test_sales_transactions_include_customer_purchase_and_amount(): void
    {
        $this->paidOrder();

        $this->withToken($this->token)->getJson('/api/admin/reports/transactions?q=juan')
            ->assertOk()
            ->assertJsonPath('totals.amount', 6000)
            ->assertJsonPath('data.0.customer.email', 'juan@example.com')
            ->assertJsonPath('data.0.order.items.0.qty', 2)
            ->assertJsonPath('data.0.amount', 6000);

        $this->withToken($this->token)->getJson('/api/admin/reports/transactions?q=nobody')
            ->assertJsonPath('totals.count', 0);
    }

    public function test_invoice_is_generated_then_editable_then_resettable(): void
    {
        $order = $this->paidOrder();

        $invoice = $this->withToken($this->token)->getJson("/api/admin/orders/{$order->id}/invoice")
            ->assertOk()
            ->assertJsonPath('edited', false)
            ->assertJsonPath('data.number', 'INV-000001')
            ->assertJsonPath('data.items.0.unit_price', 3000)
            ->json('data');

        $invoice['items'][] = ['description' => 'Installation', 'qty' => 1, 'unit_price' => 500];
        $this->withToken($this->token)->putJson("/api/admin/orders/{$order->id}/invoice", $invoice)
            ->assertOk()->assertJsonPath('edited', true);

        $this->withToken($this->token)->getJson("/api/admin/orders/{$order->id}/invoice")
            ->assertJsonPath('edited', true)
            ->assertJsonPath('data.items.1.description', 'Installation');

        $this->withToken($this->token)->deleteJson("/api/admin/orders/{$order->id}/invoice")
            ->assertJsonPath('edited', false)
            ->assertJsonCount(1, 'data.items');
    }

    public function test_other_expenses_need_a_description_and_invoice_reference(): void
    {
        $categoryId = fn (string $name) => \App\Models\ExpenseCategory::where('name', $name)->value('id');
        $base = ['title' => 'Misc', 'expense_category_id' => $categoryId('Other'), 'amount' => 250, 'incurred_on' => now()->toDateString()];

        $this->withToken($this->token)->postJson('/api/admin/expenses', $base)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['notes', 'invoice_reference']);

        $this->withToken($this->token)->postJson('/api/admin/expenses', $base + [
            'notes' => 'Office supplies', 'invoice_reference' => 'OR-1234',
        ])->assertCreated()->assertJsonPath('data.invoice_reference', 'OR-1234');

        // Other categories stay optional.
        $this->withToken($this->token)->postJson('/api/admin/expenses', ['expense_category_id' => $categoryId('Rent')] + $base)->assertCreated();
    }
}
