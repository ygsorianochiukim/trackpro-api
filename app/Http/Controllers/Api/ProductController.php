<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\BandwidthPackages;
use App\Support\HardwareOptions;
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
            ->get()
            ->each(fn (Product $p) => self::withCartDefaults($p));

        return response()->json(['data' => $products]);
    }

    /** GET /api/products/{slug} — single product by slug */
    public function show(Product $product): JsonResponse
    {
        abort_unless($product->is_active, 404);
        self::withCartDefaults($product);

        return response()->json(['data' => $product]);
    }

    /** Fill choices the product hasn't set with the store defaults the cart prices them by. */
    private static function withCartDefaults(Product $product): void
    {
        $product->hardware_options = HardwareOptions::for($product);
        $product->bandwidth_options = BandwidthPackages::for($product);
        $product->offers_bandwidth ??= true;
    }
}
