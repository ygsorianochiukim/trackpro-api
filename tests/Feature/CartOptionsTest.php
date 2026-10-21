<?php

namespace Tests\Feature;

use App\Models\AddOn;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartOptionsTest extends TestCase
{
    use RefreshDatabase;

    private Product $dashcam;

    protected function setUp(): void
    {
        parent::setUp();

        AddOn::create(['slug' => 'sd-256', 'name' => 'SanDisk 256 GB', 'price' => 900]);
        AddOn::create(['slug' => 'fuse-set', 'name' => '8 PCS Fuse Set', 'price' => 150]);
        AddOn::create(['slug' => 'not-offered', 'name' => 'Other add-on', 'price' => 50]);

        SubscriptionPlan::create(['slug' => 'test-standard', 'name' => 'Standard', 'price' => 599, 'billing_period' => 'monthly', 'features' => [], 'is_active' => true]);
        SubscriptionPlan::create(['slug' => 'yearly-pro', 'name' => 'Pro Yearly', 'price' => 6000, 'billing_period' => 'yearly', 'features' => [], 'is_active' => true]);

        Setting::put('bandwidth_packages', [
            ['slug' => '5gb', 'name' => '5 GB / month', 'description' => '', 'price' => 199, 'is_active' => true],
        ]);

        $this->dashcam = Product::create([
            'slug' => 'v7-pro', 'name' => 'V7 Pro', 'model' => 'Dash Cam', 'category' => 'Video', 'price' => 5000,
            'subscription' => 0, 'tagline' => '4G dash cam', 'highlights' => [], 'stock' => 10, 'is_active' => true,
            'hardware_options' => [
                ['name' => 'Device only', 'price' => 5000],
                ['name' => 'Device + IoT', 'price' => 5500],
                ['name' => 'Device + IoT + Installation', 'price' => 6300],
            ],
            'add_on_slugs' => ['sd-256', 'fuse-set'],
            'offers_bandwidth' => true,
            'requires_plan' => true,
        ]);
    }

    private function customerToken(): string
    {
        $customer = Customer::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'secret-password']);
        $customer->forceFill(['email_verified_at' => now()])->save();

        return $customer->createToken('customer')->plainTextToken;
    }

    private function order(array $items): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($this->customerToken())->postJson('/api/orders', [
            'items' => $items,
            'customer' => ['name' => 'Ana', 'email' => 'ana@example.com', 'phone' => '0917', 'address' => 'Makati'],
        ]);
    }

    public function test_order_is_priced_from_the_chosen_options(): void
    {
        $this->order([[
            'slug' => 'v7-pro', 'qty' => 2,
            'options' => ['hardware' => 'Device + IoT', 'add_ons' => ['sd-256', 'fuse-set'], 'bandwidth' => '5gb', 'plan' => 'test-standard'],
        ]])
            ->assertCreated()
            ->assertJsonPath('order.hardware_subtotal', (5500 + 900 + 150) * 2)
            ->assertJsonPath('order.plan_total', (599 + 199) * 2)
            ->assertJsonPath('order.total', (5500 + 900 + 150 + 599 + 199) * 2)
            ->assertJsonPath('order.items.0.unit_price', 6550)
            ->assertJsonPath('order.items.0.options.hardware.name', 'Device + IoT')
            ->assertJsonPath('order.items.0.options.bandwidth.price', 199);

        $this->assertSame(8, $this->dashcam->fresh()->stock);
    }

    public function test_yearly_plan_charges_twelve_months_of_bandwidth(): void
    {
        $this->order([[
            'slug' => 'v7-pro', 'qty' => 1,
            'options' => ['hardware' => 'Device only', 'bandwidth' => '5gb', 'plan' => 'yearly-pro'],
        ]])->assertCreated()->assertJsonPath('order.plan_total', 6000 + 199 * 12);
    }

    public function test_required_choices_and_offered_add_ons_are_enforced(): void
    {
        $this->order([['slug' => 'v7-pro', 'qty' => 1, 'options' => ['plan' => 'test-standard']]])
            ->assertStatus(422)->assertJsonPath('message', 'Choose a hardware option for V7 Pro.');

        $this->app['auth']->forgetGuards();
        Customer::query()->delete();
        $this->order([['slug' => 'v7-pro', 'qty' => 1, 'options' => ['hardware' => 'Device only']]])
            ->assertStatus(422)->assertJsonPath('message', 'Choose a platform subscription for V7 Pro.');

        $this->app['auth']->forgetGuards();
        Customer::query()->delete();
        $this->order([['slug' => 'v7-pro', 'qty' => 1, 'options' => ['hardware' => 'Device only', 'plan' => 'test-standard', 'add_ons' => ['not-offered']]]])
            ->assertStatus(422);

        $this->assertSame(10, $this->dashcam->fresh()->stock);
    }

    public function test_paying_charges_the_total_and_starts_the_chosen_plan(): void
    {
        $token = $this->customerToken();
        $reference = $this->withToken($token)->postJson('/api/orders', [
            'items' => [
                ['slug' => 'v7-pro', 'qty' => 2, 'options' => ['hardware' => 'Device only', 'bandwidth' => '5gb', 'plan' => 'test-standard']],
                ['slug' => 'v7-pro', 'qty' => 1, 'options' => ['hardware' => 'Device + IoT + Installation', 'plan' => 'test-standard']],
            ],
            'customer' => ['name' => 'Ana', 'email' => 'ana@example.com', 'phone' => '0917', 'address' => 'Makati'],
        ])->assertCreated()->json('order.reference');

        $this->withToken($token)->postJson("/api/orders/{$reference}/pay")->assertOk();

        $order = Order::where('reference', $reference)->first();
        $this->assertSame(2 * 5000 + 6300 + 2 * (599 + 199) + 599, $order->total);
        $this->assertSame($order->total, $order->payments()->first()->amount);

        // One subscription per plan + bandwidth combination, first month already paid.
        $subs = $order->subscriptions()->orderBy('id')->get();
        $this->assertCount(2, $subs);
        $this->assertSame('Standard + 5 GB / month', $subs[0]->plan_name);
        $this->assertSame(2, $subs[0]->quantity);
        $this->assertSame(798, $subs[0]->unit_price);
        $this->assertSame('Standard', $subs[1]->plan_name);
        $this->assertSame('active', $subs[1]->status);
        $this->assertSame(now()->addMonthNoOverflow()->toDateString(), $subs[1]->renews_at->toDateString());
    }

    public function test_unconfigured_product_offers_every_active_add_on(): void
    {
        $this->dashcam->update(['add_on_slugs' => null, 'hardware_options' => null]);

        $this->order([['slug' => 'v7-pro', 'qty' => 1, 'options' => ['hardware' => 'Device', 'plan' => 'test-standard', 'add_ons' => ['not-offered']]]])
            ->assertCreated()
            ->assertJsonPath('order.hardware_subtotal', 5000 + 50)
            ->assertJsonPath('order.items.0.options.hardware', null);
    }

    public function test_unconfigured_products_get_store_default_hardware_and_bandwidth(): void
    {
        $admin = User::factory()->create()->createToken('admin', ['admin'])->plainTextToken;
        $this->withToken($admin)->putJson('/api/admin/hardware-defaults', ['options' => [
            ['name' => 'Device', 'extra' => 0],
            ['name' => 'Device + IoT', 'extra' => 500],
            ['name' => 'Device + IoT + Installation', 'extra' => 1300],
            ['name' => '', 'extra' => 9],
        ]])->assertOk()->assertJsonCount(3, 'data');
        $this->app['auth']->forgetGuards();

        $this->dashcam->update(['hardware_options' => null, 'offers_bandwidth' => null]);

        $this->getJson('/api/products/v7-pro')
            ->assertJsonPath('data.hardware_options.2.name', 'Device + IoT + Installation')->assertJsonPath('data.hardware_options.2.price', 6300)
            ->assertJsonPath('data.offers_bandwidth', true);

        $this->order([['slug' => 'v7-pro', 'qty' => 1, 'options' => ['hardware' => 'Device + IoT', 'bandwidth' => '5gb', 'plan' => 'test-standard']]])
            ->assertCreated()
            ->assertJsonPath('order.items.0.unit_price', 5500)
            ->assertJsonPath('order.plan_total', 599 + 199);

        // A product's own list wins over the defaults.
        $this->dashcam->update(['hardware_options' => [['name' => 'Kit', 'price' => 7000]]]);
        $this->getJson('/api/products/v7-pro')->assertJsonPath('data.hardware_options.0.name', 'Kit')->assertJsonPath('data.hardware_options.0.price', 7000);
    }

    public function test_product_bandwidth_options_replace_the_defaults(): void
    {
        $admin = User::factory()->create()->createToken('admin', ['admin'])->plainTextToken;

        // No packages of its own: the default 5 GB package is offered.
        $this->getJson('/api/products/v7-pro')->assertJsonPath('data.bandwidth_options.0.slug', '5gb');

        $this->withToken($admin)->patchJson("/api/admin/products/{$this->dashcam->id}", [
            'bandwidth_options' => [
                ['name' => '20 GB / month', 'description' => 'Heavy video', 'price' => 499],
                ['name' => '', 'price' => 1],
            ],
        ])->assertOk()->assertJsonPath('data.bandwidth_options', [
            ['slug' => '20-gb-month', 'name' => '20 GB / month', 'description' => 'Heavy video', 'price' => 499],
        ]);
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/products/v7-pro')->assertJsonCount(1, 'data.bandwidth_options');

        $this->order([['slug' => 'v7-pro', 'qty' => 1, 'options' => ['hardware' => 'Device only', 'plan' => 'test-standard', 'bandwidth' => '20-gb-month']]])
            ->assertCreated()
            ->assertJsonPath('order.plan_total', 599 + 499);

        // The default package isn't offered on this product any more.
        $this->app['auth']->forgetGuards();
        Customer::query()->delete();
        $this->order([['slug' => 'v7-pro', 'qty' => 1, 'options' => ['hardware' => 'Device only', 'plan' => 'test-standard', 'bandwidth' => '5gb']]])
            ->assertStatus(422);

        // Switched off: nothing offered.
        $this->dashcam->update(['offers_bandwidth' => false]);
        $this->getJson('/api/products/v7-pro')->assertJsonPath('data.bandwidth_options', []);
    }

    public function test_hardware_options_carry_an_image(): void
    {
        $admin = User::factory()->create()->createToken('admin', ['admin'])->plainTextToken;

        $this->withToken($admin)->patchJson("/api/admin/products/{$this->dashcam->id}", [
            'hardware_options' => [
                ['name' => 'Device only', 'price' => 5000, 'image' => 'media/device.png'],
                ['name' => 'Device + IoT', 'price' => 5500],
            ],
        ])->assertOk()
            ->assertJsonPath('data.hardware_options.0.image', 'media/device.png')
            ->assertJsonPath('data.hardware_options.1.image_url', null);

        // Not an uploaded path — rejected.
        $this->withToken($admin)->patchJson("/api/admin/products/{$this->dashcam->id}", [
            'hardware_options' => [['name' => 'X', 'price' => 1, 'image' => 'https://evil.example/x.png']],
        ])->assertUnprocessable();

        $this->withToken($admin)->putJson('/api/admin/hardware-defaults', ['options' => [
            ['name' => 'Device', 'extra' => 0, 'image' => 'media/default.png'],
        ]])->assertOk()->assertJsonPath('data.0.image', 'media/default.png');
        $this->app['auth']->forgetGuards();

        $url = $this->getJson('/api/products/v7-pro')->json('data.hardware_options.0.image_url');
        $this->assertStringEndsWith('media/device.png', $url);

        $this->dashcam->update(['hardware_options' => null]);
        $url = $this->getJson('/api/products/v7-pro')->json('data.hardware_options.0.image_url');
        $this->assertStringEndsWith('media/default.png', $url);
    }

    public function test_products_without_options_still_order_at_base_price(): void
    {
        Product::create([
            'slug' => 'fuse', 'name' => 'Spare fuse', 'model' => 'Accessory', 'category' => 'Accessory', 'price' => 120,
            'tagline' => 'Spare', 'highlights' => [], 'stock' => 5, 'is_active' => true, 'requires_plan' => false,
        ]);

        $this->order([['slug' => 'fuse', 'qty' => 3]])
            ->assertCreated()
            ->assertJsonPath('order.total', 360)
            ->assertJsonPath('order.items.0.options', null);
    }

    public function test_admin_manages_bandwidth_packages_and_product_options(): void
    {
        $admin = User::factory()->create()->createToken('admin', ['admin'])->plainTextToken;

        $this->withToken($admin)->putJson('/api/admin/bandwidth-packages', ['packages' => [
            ['name' => '10 GB / month', 'price' => 349, 'is_active' => true],
            ['name' => 'Unlimited', 'price' => 999, 'is_active' => false],
        ]])->assertOk()->assertJsonPath('data.0.slug', '10-gb-month');

        $this->getJson('/api/bandwidth-packages')->assertOk()->assertJsonCount(1, 'data');

        $this->withToken($admin)->patchJson("/api/admin/products/{$this->dashcam->id}", [
            'hardware_options' => [['name' => ' Device only ', 'price' => 4800], ['name' => '', 'price' => 1]],
            'add_on_slugs' => ['sd-256'],
            'offers_bandwidth' => false,
        ])->assertOk()
            ->assertJsonPath('data.hardware_options', [['name' => 'Device only', 'price' => 4800, 'image' => null, 'image_url' => null]])
            ->assertJsonPath('data.add_on_slugs', ['sd-256'])
            ->assertJsonPath('data.offers_bandwidth', false);
    }
}
