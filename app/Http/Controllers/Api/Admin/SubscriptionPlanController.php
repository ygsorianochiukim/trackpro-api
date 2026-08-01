<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        ])->orderBy('price')->get();

        return response()->json(['data' => $plans]);
    }

    /** POST /api/admin/subscription-plans */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($data['name']);

        $plan = SubscriptionPlan::create($data);
        $this->syncDefault($plan);

        return response()->json(['data' => $plan->fresh()], 201);
    }

    /** PUT|PATCH /api/admin/subscription-plans/{plan} */
    public function update(Request $request, SubscriptionPlan $plan): JsonResponse
    {
        $plan->update($this->validated($request, $plan));
        $this->syncDefault($plan);

        return response()->json(['data' => $plan->fresh()]);
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
        return $request->validate([
            'name' => [$plan ? 'sometimes' : 'required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => [$plan ? 'sometimes' : 'required', 'integer', 'min:0'],
            'billing_period' => ['nullable', Rule::in(['yearly', 'monthly'])],
            'features' => ['nullable', 'array'],
            'features.*' => ['string', 'max:160'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);
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
