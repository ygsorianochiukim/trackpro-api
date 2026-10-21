<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\BandwidthPackages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Video bandwidth packages — the public list for the cart, and the admin editor. */
class BandwidthPackageController extends Controller
{
    /** GET /api/bandwidth-packages — active packages only. */
    public function index(): JsonResponse
    {
        return response()->json(['data' => array_map(
            fn ($p) => array_diff_key($p, ['is_active' => true]),
            BandwidthPackages::active(),
        )]);
    }

    /** GET /api/admin/bandwidth-packages — every package, active or not. */
    public function adminIndex(): JsonResponse
    {
        return response()->json(['data' => BandwidthPackages::all()]);
    }

    /** PUT /api/admin/bandwidth-packages — replace the whole list. */
    public function update(Request $request): JsonResponse
    {
        return response()->json(['data' => BandwidthPackages::saveFrom($request)]);
    }
}
