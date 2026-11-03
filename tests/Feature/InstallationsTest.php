<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Installation;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallationsTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    /** @var array<string, array<string, mixed>> */
    private array $crew = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = User::factory()->create()->createToken('admin', ['admin'])->plainTextToken;

        // Lapasan, CDO is the usual job site: Ramon is ~3 km away, Joy ~53 km (Iligan), Ben ~180 km (Davao).
        $this->crew['ramon'] = $this->hire(['name' => 'Ramon Uy', 'base_address' => 'Carmen, CDO', 'base_lat' => 8.4772, 'base_lng' => 124.6264]);
        $this->crew['joy'] = $this->hire(['name' => 'Joy Lim', 'base_address' => 'Iligan City', 'base_lat' => 8.2280, 'base_lng' => 124.2452]);
        $this->crew['ben'] = $this->hire(['name' => 'Ben Cruz', 'base_address' => 'Davao City', 'base_lat' => 7.0731, 'base_lng' => 125.6128]);
    }

    private function api(): static
    {
        return $this->withToken($this->token);
    }

    private function hire(array $data): array
    {
        return $this->api()->postJson('/api/admin/installers', ['daily_capacity' => 3, 'service_radius_km' => 30, ...$data])
            ->assertCreated()->json('data');
    }

    private function book(array $data = [])
    {
        return $this->api()->postJson('/api/admin/installations', [
            'title' => 'GPS tracker install',
            'address' => 'Lapasan, Cagayan de Oro',
            'lat' => 8.4822,
            'lng' => 124.6573,
            ...$data,
        ]);
    }

    private function suggest(string $query): array
    {
        return $this->api()->getJson('/api/admin/installations/suggest?lat=8.4822&lng=124.6573&'.$query)->assertOk()->json('data');
    }

    public function test_admin_routes_need_an_admin_token(): void
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/installations')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $customer = Customer::create(['name' => 'C', 'email' => 'c@example.com', 'password' => 'secret123']);
        $this->withToken($customer->createToken('c')->plainTextToken)->getJson('/api/admin/installations')->assertForbidden();
    }

    public function test_jobs_are_numbered_and_stay_in_the_backlog_until_they_have_a_person_and_a_date(): void
    {
        $first = $this->book()->assertCreated()->json('data');
        $second = $this->book(['installer_id' => $this->crew['ramon']['id']])->json('data');
        $third = $this->book(['installer_id' => $this->crew['ramon']['id'], 'scheduled_on' => '2026-11-10', 'start_time' => '09:00'])->json('data');

        $this->assertSame('INS-000001', $first['reference']);
        $this->assertSame('pending', $first['status']);
        $this->assertSame('pending', $second['status']);
        $this->assertSame('scheduled', $third['status']);
        $this->assertSame('Ramon Uy', $third['installer_name']);
    }

    public function test_a_job_can_be_booked_against_a_paid_order(): void
    {
        $order = Order::create([
            'reference' => 'TP-000123', 'customer_name' => 'Juan', 'customer_email' => 'j@example.com',
            'customer_phone' => '0917', 'delivery_address' => 'Lapasan', 'hardware_subtotal' => 5000, 'total' => 5000,
            'payment_status' => 'paid',
        ]);

        $options = $this->api()->getJson('/api/admin/installations/options')->assertOk()->json();
        $this->assertSame(['TP-000123'], array_column($options['orders'], 'reference'));

        $job = $this->book(['order_id' => $order->id, 'units' => 4])->assertCreated()->json('data');
        $this->assertSame('TP-000123', $job['order_reference']);
        $this->assertSame(4, $job['units']);

        // Once booked, the order drops off the "still to install" list.
        $this->assertSame([], $this->api()->getJson('/api/admin/installations/options')->json('orders'));
    }

    public function test_one_person_cannot_be_double_booked_into_overlapping_hours(): void
    {
        $ramon = $this->crew['ramon']['id'];
        $this->book(['installer_id' => $ramon, 'scheduled_on' => '2026-11-10', 'start_time' => '09:00', 'duration_minutes' => 180])->assertCreated();

        $this->book(['installer_id' => $ramon, 'scheduled_on' => '2026-11-10', 'start_time' => '11:00'])
            ->assertStatus(422)->assertJsonValidationErrors('start_time');

        $this->book(['installer_id' => $ramon, 'scheduled_on' => '2026-11-10', 'start_time' => '12:00'])->assertCreated();
    }

    public function test_inactive_installers_get_no_new_work(): void
    {
        $this->api()->patchJson("/api/admin/installers/{$this->crew['ramon']['id']}", ['is_active' => false])->assertOk();

        $this->book(['installer_id' => $this->crew['ramon']['id'], 'scheduled_on' => '2026-11-10'])
            ->assertStatus(422)->assertJsonValidationErrors('installer_id');
    }

    public function test_the_nearest_free_installer_is_suggested_first(): void
    {
        $rows = $this->suggest('date=2026-11-10&start_time=09:00');

        $this->assertSame(['Ramon Uy', 'Joy Lim', 'Ben Cruz'], array_column($rows, 'name'));
        $this->assertTrue($rows[0]['recommended']);
        $this->assertLessThan(5, $rows[0]['distance_km']);
        $this->assertTrue($rows[0]['within_radius']);
        $this->assertFalse($rows[1]['within_radius']);
    }

    public function test_somebody_busy_at_that_hour_is_passed_over(): void
    {
        $this->book(['installer_id' => $this->crew['ramon']['id'], 'scheduled_on' => '2026-11-10', 'start_time' => '08:00', 'duration_minutes' => 240]);

        $rows = $this->suggest('date=2026-11-10&start_time=10:00');

        $this->assertSame('Joy Lim', $rows[0]['name']);
        $this->assertTrue($rows[0]['recommended']);
        $this->assertSame('Ramon Uy', $rows[2]['name']);
        $this->assertFalse($rows[2]['available']);
        $this->assertSame('INS-000001', $rows[2]['clash']['reference']);
    }

    public function test_distance_is_measured_from_a_job_already_booked_nearby_that_day(): void
    {
        $this->book([
            'installer_id' => $this->crew['ben']['id'], 'scheduled_on' => '2026-11-10', 'start_time' => '08:00',
            'address' => 'Bulua, CDO', 'lat' => 8.4900, 'lng' => 124.6400,
        ])->assertCreated();

        $ben = collect($this->suggest('date=2026-11-10&start_time=13:00'))->firstWhere('name', 'Ben Cruz');

        $this->assertSame('job', $ben['from']['kind']);
        $this->assertLessThan(5, $ben['distance_km']);
        $this->assertGreaterThan(150, $ben['base_distance_km']);
    }

    public function test_a_full_day_is_not_recommended(): void
    {
        $this->api()->patchJson("/api/admin/installers/{$this->crew['ramon']['id']}", ['daily_capacity' => 1])->assertOk();
        $this->book(['installer_id' => $this->crew['ramon']['id'], 'scheduled_on' => '2026-11-10'])->assertCreated();

        $rows = collect($this->suggest('date=2026-11-10'))->keyBy('name');

        $this->assertTrue($rows['Ramon Uy']['full']);
        $this->assertFalse($rows['Ramon Uy']['recommended']);
        $this->assertTrue($rows['Joy Lim']['recommended']);
    }

    public function test_dragging_on_the_schedule_reassigns_and_redates(): void
    {
        $job = $this->book(['installer_id' => $this->crew['ramon']['id'], 'scheduled_on' => '2026-11-10'])->json('data');

        $moved = $this->api()->postJson("/api/admin/installations/{$job['id']}/schedule", [
            'installer_id' => $this->crew['joy']['id'], 'scheduled_on' => '2026-11-12',
        ])->assertOk()->json('data');

        $this->assertSame('Joy Lim', $moved['installer_name']);
        $this->assertSame('2026-11-12', $moved['scheduled_on']);

        $undated = $this->api()->postJson("/api/admin/installations/{$job['id']}/schedule", ['scheduled_on' => null])
            ->assertOk()->json('data');
        $this->assertSame('pending', $undated['status']);
    }

    public function test_the_board_needs_a_person_and_date_before_scheduled(): void
    {
        $job = $this->book()->json('data');

        $this->api()->postJson("/api/admin/installations/{$job['id']}/move", ['status' => 'scheduled'])
            ->assertStatus(422)->assertJsonValidationErrors(['installer_id', 'scheduled_on']);
    }

    public function test_moving_across_the_board_stamps_start_and_finish(): void
    {
        $this->travelTo('2026-11-10 01:30:00');
        $job = $this->book(['installer_id' => $this->crew['ramon']['id'], 'scheduled_on' => '2026-11-10'])->json('data');

        $this->api()->postJson("/api/admin/installations/{$job['id']}/move", ['status' => 'in_progress'])->assertOk();
        $this->travelTo('2026-11-10 04:00:00');
        $done = $this->api()->postJson("/api/admin/installations/{$job['id']}/move", ['status' => 'completed'])->assertOk()->json('data');

        $this->assertSame('completed', $done['status']);
        $this->assertStringStartsWith('2026-11-10T01:30:00', $done['started_at']);
        $this->assertStringStartsWith('2026-11-10T04:00:00', $done['completed_at']);
    }

    public function test_the_board_keeps_the_order_cards_were_dropped_in(): void
    {
        $this->book(['title' => 'A']);
        $this->book(['title' => 'B']);
        $c = $this->book(['title' => 'C'])->json('data');

        $this->api()->postJson("/api/admin/installations/{$c['id']}/move", ['status' => 'pending', 'index' => 0])->assertOk();

        $titles = array_column($this->api()->getJson('/api/admin/installations?status[]=pending')->json('data'), 'title');
        $this->assertSame(['C', 'A', 'B'], $titles);
    }

    public function test_removing_an_installer_returns_their_jobs_to_the_backlog(): void
    {
        $job = $this->book(['installer_id' => $this->crew['ramon']['id'], 'scheduled_on' => '2026-11-10'])->json('data');

        $this->api()->deleteJson("/api/admin/installers/{$this->crew['ramon']['id']}")->assertOk();

        $job = Installation::find($job['id']);
        $this->assertSame('pending', $job->status);
        $this->assertNull($job->installer_id);
    }

    public function test_the_dashboard_counts_today_the_backlog_and_each_persons_week(): void
    {
        // Wednesday 11 Nov 2026, 10:00 in Manila.
        $this->travelTo('2026-11-11 02:00:00');
        $ramon = $this->crew['ramon']['id'];

        $this->book(['installer_id' => $ramon, 'scheduled_on' => '2026-11-11', 'start_time' => '09:00']);
        $this->book(['installer_id' => $ramon, 'scheduled_on' => '2026-11-13']);
        $this->book();
        $this->book(['installer_id' => $this->crew['joy']['id'], 'scheduled_on' => '2026-11-03']);

        $d = $this->api()->getJson('/api/admin/installations/dashboard')->assertOk()->json();

        $this->assertSame(1, $d['kpis']['today']);
        $this->assertSame(2, $d['kpis']['week']);
        $this->assertSame(1, $d['kpis']['backlog']);
        $this->assertSame(1, $d['kpis']['overdue']);
        $this->assertSame('Ramon Uy', $d['load'][0]['name']);
        $this->assertSame([0, 0, 1, 0, 1, 0, 0], $d['load'][0]['days']);
        $this->assertSame(1, $d['load'][0]['today']);
        $this->assertCount(3, $d['map']['installers']);
    }
}
