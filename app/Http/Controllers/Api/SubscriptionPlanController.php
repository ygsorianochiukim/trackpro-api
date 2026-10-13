<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;

class SubscriptionPlanController extends Controller
{
    /** GET /api/subscription-plans — plans on sale, for the homepage and the account UI. */
    public function index(): JsonResponse
    {
        $plans = SubscriptionPlan::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('price')
            ->get([
                'id', 'slug', 'name', 'description', 'fleet_size', 'price', 'billing_period',
                'features', 'recommended_for', 'comparison', 'is_popular', 'is_default',
            ]);

        return response()->json(['data' => $plans]);
    }
}
