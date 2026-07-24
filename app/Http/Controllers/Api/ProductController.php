<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    /** GET /api/products — list all active products */
    public function index(): JsonResponse
    {
        $products = Product::query()
            ->where('is_active', true)
            ->orderByDesc('featured')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $products]);
    }

    /** GET /api/products/{slug} — single product by slug */
    public function show(Product $product): JsonResponse
    {
        abort_unless($product->is_active, 404);

        return response()->json(['data' => $product]);
    }
}
