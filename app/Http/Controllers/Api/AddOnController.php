<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AddOn;
use Illuminate\Http\JsonResponse;

class AddOnController extends Controller
{
    /** GET /api/add-ons — published add-ons, in display order, for the storefront. */
    public function index(): JsonResponse
    {
        $addOns = AddOn::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'slug', 'name', 'subtitle', 'price', 'icon', 'description', 'features', 'devices', 'media', 'warranty']);

        return response()->json(['data' => $addOns]);
    }
}
