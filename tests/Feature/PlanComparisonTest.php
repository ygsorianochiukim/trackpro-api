<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanComparisonTest extends TestCase
{
    use RefreshDatabase;

    private function plan(string $slug, int $price): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'slug' => $slug, 'name' => ucfirst($slug), 'price' => $price, 'billing_period' => 'monthly',
            'is_active' => true, 'sort_order' => $price,
        ]);
    }

    public function test_admin_saves_the_whole_comparison_grid(): void
    {
        $token = User::factory()->create()->createToken('admin', ['admin'])->plainTextToken;
        $basic = $this->plan('t-basic', 299);
        $pro = $this->plan('t-pro', 999);

        $this->withToken($token)->putJson('/api/admin/subscription-plans/comparison', [
            'features' => ['Mobile App', 'Video Surveillance', 'Technical Support'],
            'plans' => [
                ['id' => $basic->id, 'values' => [true, false, ' Business-Hours ']],
                ['id' => $pro->id, 'values' => [true, true, '']],
            ],
        ])->assertOk();

        $this->assertSame([
            ['feature' => 'Mobile App', 'value' => true],
            ['feature' => 'Video Surveillance', 'value' => false],
            ['feature' => 'Technical Support', 'value' => 'Business-Hours'],
        ], $basic->fresh()->comparison);
        $this->assertSame(false, $pro->fresh()->comparison[2]['value']);

        // Public homepage feed carries it.
        $pro = collect($this->getJson('/api/subscription-plans')->json('data'))->firstWhere('slug', 't-pro');
        $this->assertSame(['feature' => 'Video Surveillance', 'value' => true], $pro['comparison'][1]);
    }

    public function test_duplicate_features_are_rejected(): void
    {
        $token = User::factory()->create()->createToken('admin', ['admin'])->plainTextToken;
        $basic = $this->plan('t-basic', 299);

        $this->withToken($token)->putJson('/api/admin/subscription-plans/comparison', [
            'features' => ['Mobile App', 'Mobile App'],
            'plans' => [['id' => $basic->id, 'values' => [true, true]]],
        ])->assertUnprocessable();
    }

    public function test_requires_admin(): void
    {
        $this->putJson('/api/admin/subscription-plans/comparison', ['features' => [], 'plans' => []])
            ->assertUnauthorized();
    }
}
