<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    /** GET /api/admin/products — paginated (10/page, incl. inactive). */
    public function index(): JsonResponse
    {
        $p = Product::orderBy('id')->paginate(10);

        return response()->json([
            'data' => $p->items(),
            'meta' => [
                'current_page' => $p->currentPage(),
                'last_page' => $p->lastPage(),
                'total' => $p->total(),
                'has_more' => $p->hasMorePages(),
            ],
        ]);
    }

    /** GET /api/admin/products/{id} */
    public function show(Product $product): JsonResponse
    {
        return response()->json(['data' => $product]);
    }

    /** POST /api/admin/products */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:products,slug'],
            'model' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'integer', 'min:0'],
            'subscription' => ['required', 'integer', 'min:0'],
            'tagline' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:5000'],
            'highlights' => ['nullable', 'array'],
            'highlights.*' => ['string', 'max:255'],
            'stock' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
            'featured' => ['required', 'boolean'],
        ]);

        $slug = ($data['slug'] ?? null) ?: Str::slug($data['name']);
        $base = $slug;
        $i = 2;
        while (Product::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }
        $data['slug'] = $slug;
        $data['highlights'] = array_values(array_filter(array_map('trim', $data['highlights'] ?? [])));

        $product = Product::create($data);

        return response()->json(['data' => $product], 201);
    }

    /** PATCH /api/admin/products/{id} */
    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'price' => ['required', 'integer', 'min:0'],
            'subscription' => ['required', 'integer', 'min:0'],
            'stock' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
            'featured' => ['required', 'boolean'],
        ]);

        $product->update($data);

        return response()->json(['data' => $product]);
    }

    /** POST /api/admin/products/{id}/stock — relative adjustment. */
    public function adjustStock(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate(['delta' => ['required', 'integer']]);
        $product->update(['stock' => max(0, $product->stock + $data['delta'])]);

        return response()->json(['data' => $product]);
    }
}
