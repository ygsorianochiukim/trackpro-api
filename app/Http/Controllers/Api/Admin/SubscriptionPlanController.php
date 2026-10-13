<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SubscriptionPlanController extends Controller
{
    /** GET /api/admin/subscription-plans */
    public function index(): JsonResponse
    {
        $plans = SubscriptionPlan::withCount([
            'subscriptions',
            'subscriptions as active_subscriptions_count' => fn ($q) => $q->where('status', 'active'),
        ])->orderBy('sort_order')->orderBy('price')->get();

        return response()->json(['data' => $plans]);
    }

    /** POST /api/admin/subscription-plans */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($data['name']);
        $data['sort_order'] ??= (int) SubscriptionPlan::max('sort_order') + 1;

        $plan = SubscriptionPlan::create($data);
        $this->syncDefault($plan);

        return response()->json(['data' => $plan->fresh()], 201);
    }

    /**
     * PUT /api/admin/subscription-plans/comparison
     *
     * Saves the whole homepage comparison table at once, from the admin grid:
     * `features` is the row order, and each plan's `values` line up with it
     * (true = ✓, false = —, text is shown as-is). Every plan gets the full row
     * list, so the table order is the same whichever plans a visitor picks.
     */
    public function comparison(Request $request): JsonResponse
    {
        $data = $request->validate([
            'features' => ['present', 'array', 'max:200'],
            'features.*' => ['required', 'string', 'max:160', 'distinct'],
            'plans' => ['required', 'array'],
            'plans.*.id' => ['required', 'integer', 'exists:subscription_plans,id'],
            'plans.*.values' => ['present', 'array'],
            'plans.*.values.*' => ['nullable'],
        ]);

        $features = array_map('trim', $data['features']);

        DB::transaction(function () use ($data, $features) {
            foreach ($data['plans'] as $row) {
                $comparison = [];
                foreach ($features as $i => $feature) {
                    $comparison[] = ['feature' => $feature, 'value' => $this->comparisonValue($row['values'][$i] ?? false)];
                }
                SubscriptionPlan::findOrFail($row['id'])->update(['comparison' => $comparison]);
            }
        });

        return response()->json(['message' => 'Comparison table saved.']);
    }

    /**
     * PUT|PATCH /api/admin/subscription-plans/{plan}
     *
     * A change to the name, price or billing period carries over to every live
     * subscriber on the plan, so the plan stays the single source of truth.
     * It applies from their next invoice — invoices already raised keep the
     * amount they were issued for (void and re-raise one to re-price it).
     */
    public function update(Request $request, SubscriptionPlan $plan): JsonResponse
    {
        $plan->update($this->validated($request, $plan));
        $this->syncDefault($plan);

        $updated = 0;
        if ($plan->wasChanged(['name', 'price', 'billing_period'])) {
            $updated = $this->syncSubscribers($plan);
        }

        return response()->json([
            'data' => $plan->fresh(),
            'subscribers_updated' => $updated,
            'message' => $updated
                ? "Plan updated — {$updated} subscriber(s) will be billed the new terms from their next invoice."
                : 'Plan updated.',
        ]);
    }

    /** Push the plan's current terms onto its live subscriptions. Returns how many changed. */
    private function syncSubscribers(SubscriptionPlan $plan): int
    {
        $count = 0;

        $plan->subscriptions()
            ->whereNotIn('status', ['cancelled', 'expired'])
            ->each(function (Subscription $subscription) use ($plan, &$count) {
                $subscription->update([
                    'plan_name' => $plan->name,
                    'billing_period' => $plan->billing_period,
                    'unit_price' => $plan->price,
                    'price' => $plan->price * $subscription->quantity,
                ]);
                $count++;
            });

        return $count;
    }

    /**
     * DELETE /api/admin/subscription-plans/{plan}
     *
     * Plans in use are deactivated rather than deleted — live subscriptions
     * snapshot the plan name and price, but the row still backs their history.
     */
    public function destroy(SubscriptionPlan $plan): JsonResponse
    {
        if ($plan->subscriptions()->exists()) {
            $plan->update(['is_active' => false, 'is_default' => false]);

            return response()->json([
                'message' => 'Plan is in use by existing subscriptions — it has been deactivated instead of deleted.',
                'data' => $plan->fresh(),
            ]);
        }

        $plan->delete();

        return response()->json(['message' => 'Plan deleted.']);
    }

    private function validated(Request $request, ?SubscriptionPlan $plan = null): array
    {
        $data = $request->validate([
            'name' => [$plan ? 'sometimes' : 'required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'fleet_size' => ['nullable', 'string', 'max:60'],
            'price' => [$plan ? 'sometimes' : 'required', 'integer', 'min:0'],
            'billing_period' => ['nullable', Rule::in(['yearly', 'monthly'])],
            'features' => ['nullable', 'array'],
            'features.*' => ['nullable', 'string', 'max:160'],
            'recommended_for' => ['nullable', 'array'],
            'recommended_for.*' => ['nullable', 'string', 'max:120'],
            'comparison' => ['nullable', 'array'],
            'comparison.*.feature' => ['required', 'string', 'max:160'],
            'comparison.*.value' => ['nullable'],
            'is_popular' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // Blank order = leave it alone (store() then appends the plan last).
        if (($data['sort_order'] ?? null) === null) {
            unset($data['sort_order']);
        }

        foreach (['features', 'recommended_for'] as $list) {
            if (array_key_exists($list, $data)) {
                $data[$list] = array_values(array_filter(array_map(fn ($v) => trim((string) $v), $data[$list] ?? [])));
            }
        }

        // Comparison cells: true = ✓, false = —, anything else is shown as text.
        if (array_key_exists('comparison', $data)) {
            $data['comparison'] = array_map(
                fn (array $row) => ['feature' => trim($row['feature']), 'value' => $this->comparisonValue($row['value'] ?? false)],
                array_values($data['comparison'] ?? []),
            );
        }

        return $data;
    }

    /** A comparison cell: true = ✓, false/blank = —, other text is shown as-is. */
    private function comparisonValue(mixed $value): bool|string
    {
        if (is_bool($value)) {
            return $value;
        }
        $value = trim((string) $value);

        return $value === '' ? false : mb_substr($value, 0, 60);
    }

    /** Exactly one plan can be the provisioning default. */
    private function syncDefault(SubscriptionPlan $plan): void
    {
        if ($plan->is_default) {
            SubscriptionPlan::whereKeyNot($plan->id)->update(['is_default' => false]);
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'plan';
        $slug = $base;
        $i = 2;
        while (SubscriptionPlan::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
