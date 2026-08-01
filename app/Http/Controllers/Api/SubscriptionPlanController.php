<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;

class SubscriptionPlanController extends Controller
{
    /** GET /api/subscription-plans — published plans, for the storefront/account UI. */
    public function index(): JsonResponse
    {
        $plans = SubscriptionPlan::where('is_active', true)
            ->orderBy('price')
            ->get(['id', 'slug', 'name', 'description', 'price', 'billing_period', 'features', 'is_default']);

        return response()->json(['data' => $plans]);
    }
}
