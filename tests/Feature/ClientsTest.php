<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Notifications\CustomerResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientsTest extends TestCase
{
    use RefreshDatabase;

    private function signup(array $overrides = [])
    {
        return $this->postJson('/api/customer/register', $overrides + [
            'name' => 'Juan Dela Cruz',
            'email' => 'juan@example.com',
            'phone' => '09171234567',
            'company' => 'JDC Trucking',
            'fleet_size' => '3–10',
            'vehicle_info' => '2 Isuzu trucks, 1 L300',
            'password' => 'secret123',
            'accept_terms' => true,
        ]);
    }

    public function test_signup_requires_agreeing_to_terms(): void
    {
        $this->signup(['accept_terms' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('accept_terms');
    }

    public function test_signup_stores_profile_fields_and_terms_acceptance(): void
    {
        $this->signup()->assertCreated()->assertJsonPath('customer.company', 'JDC Trucking');

        $c = Customer::where('email', 'juan@example.com')->firstOrFail();
        $this->assertSame('3–10', $c->fleet_size);
        $this->assertSame('2 Isuzu trucks, 1 L300', $c->vehicle_info);
        $this->assertNotNull($c->terms_accepted_at);
    }

    public function test_admin_can_list_and_view_clients(): void
    {
        $this->signup();
        $customer = Customer::firstOrFail();
        $admin = User::factory()->create();
        $token = $admin->createToken('admin', ['admin'])->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/clients?search=JDC')
            ->assertOk()
            ->assertJsonPath('data.0.email', 'juan@example.com')
            ->assertJsonPath('totals.clients', 1);

        $this->withToken($token)->getJson("/api/admin/clients/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.vehicle_info', '2 Isuzu trucks, 1 L300')
            ->assertJsonStructure(['data' => ['orders', 'subscriptions', 'invoices', 'totals']]);
    }

    public function test_admin_can_add_a_client_manually(): void
    {
        Notification::fake();
        $token = User::factory()->create()->createToken('admin', ['admin'])->plainTextToken;

        $this->withToken($token)->postJson('/api/admin/clients', [
            'name' => 'Maria Santos',
            'email' => 'maria@example.com',
            'company' => 'Santos Logistics',
            'send_invite' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'maria@example.com')
            ->assertJsonPath('data.email_verified', true)
            ->assertJsonPath('invite_sent', true);

        $c = Customer::where('email', 'maria@example.com')->firstOrFail();
        $this->assertSame('Santos Logistics', $c->company);
        Notification::assertSentTo($c, CustomerResetPassword::class);

        // Duplicate email is rejected
        $this->withToken($token)->postJson('/api/admin/clients', ['name' => 'Dup', 'email' => 'maria@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_manually_added_client_can_sign_in_with_admin_set_password(): void
    {
        $token = User::factory()->create()->createToken('admin', ['admin'])->plainTextToken;

        $this->withToken($token)->postJson('/api/admin/clients', [
            'name' => 'Pedro Reyes',
            'email' => 'pedro@example.com',
            'password' => 'secret123',
        ])->assertCreated()->assertJsonPath('invite_sent', false);

        $this->postJson('/api/customer/login', ['email' => 'pedro@example.com', 'password' => 'secret123'])
            ->assertOk();
    }

    public function test_customers_cannot_add_clients(): void
    {
        $token = $this->signup()->json('token');

        $this->withToken($token)->postJson('/api/admin/clients', ['name' => 'X', 'email' => 'x@example.com'])
            ->assertForbidden();
    }

    public function test_customers_cannot_reach_admin_clients(): void
    {
        $token = $this->signup()->json('token');

        $this->withToken($token)->getJson('/api/admin/clients')->assertForbidden();
    }
}
