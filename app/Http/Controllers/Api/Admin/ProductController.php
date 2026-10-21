<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\BandwidthPackages;
use App\Support\HardwareOptions;
use App\Support\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
            'highlights.*' => ['nullable', 'string', 'max:255'],
            'stock' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
            'featured' => ['required', 'boolean'],
            ...Media::rules(),
            ...self::cartOptionRules(),
        ]);

        $slug = ($data['slug'] ?? null) ?: Str::slug($data['name']);
        $base = $slug;
        $i = 2;
        while (Product::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }
        $data['slug'] = $slug;
        $data['highlights'] = array_values(array_filter(array_map(fn ($v) => trim((string) $v), $data['highlights'] ?? [])));

        $product = Product::create(self::cleanCartOptions($data));

        return response()->json(['data' => $product], 201);
    }

    /** PATCH /api/admin/products/{id} */
    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('products', 'slug')->ignore($product->id)],
            'model' => ['sometimes', 'required', 'string', 'max:255'],
            'category' => ['sometimes', 'required', 'string', 'max:255'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:255'],
            'price' => ['sometimes', 'required', 'integer', 'min:0'],
            'subscription' => ['sometimes', 'required', 'integer', 'min:0'],
            'tagline' => ['sometimes', 'required', 'string', 'max:500'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'highlights' => ['sometimes', 'nullable', 'array'],
            'highlights.*' => ['nullable', 'string', 'max:255'],
            'stock' => ['sometimes', 'required', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'featured' => ['sometimes', 'required', 'boolean'],
            ...Media::rules(),
            ...self::cartOptionRules(),
        ]);

        // Blank slug = regenerate it from the (possibly new) name.
        if (array_key_exists('slug', $data)) {
            $data['slug'] = Str::slug(($data['slug'] ?? null) ?: ($data['name'] ?? $product->name));
            $base = $data['slug'];
            $i = 2;
            while (Product::where('slug', $data['slug'])->whereKeyNot($product->id)->exists()) {
                $data['slug'] = $base . '-' . $i++;
            }
        }
        if (array_key_exists('highlights', $data)) {
            $data['highlights'] = array_values(array_filter(array_map(fn ($v) => trim((string) $v), $data['highlights'] ?? [])));
        }

        $product->update(self::cleanCartOptions($data));

        return response()->json(['data' => $product]);
    }

    /** POST /api/admin/products/{id}/stock — relative adjustment. */
    public function adjustStock(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate(['delta' => ['required', 'integer']]);
        $product->update(['stock' => max(0, $product->stock + $data['delta'])]);

        return response()->json(['data' => $product]);
    }

    /**
     * DELETE /api/admin/products/{id}
     *
     * Products on past orders are deactivated rather than deleted — order_items
     * restrict the delete, and the row still backs order history.
     */
    public function destroy(Product $product): JsonResponse
    {
        if ($product->orderItems()->exists()) {
            $product->update(['is_active' => false, 'featured' => false]);

            return response()->json([
                'message' => 'Product has past orders — it has been deactivated (hidden from the store) instead of deleted.',
                'data' => $product->fresh(),
            ]);
        }

        $product->delete();

        return response()->json(['message' => 'Product deleted.']);
    }

    /** GET /api/admin/hardware-defaults — hardware options for products without their own. */
    public function hardwareDefaults(): JsonResponse
    {
        return response()->json(['data' => HardwareOptions::defaults()]);
    }

    /** PUT /api/admin/hardware-defaults — body: { options: [{name, extra}] } */
    public function updateHardwareDefaults(Request $request): JsonResponse
    {
        return response()->json(['data' => HardwareOptions::saveDefaultsFrom($request)]);
    }

    /** What customers pick when adding the product to the cart. */
    private static function cartOptionRules(): array
    {
        return [
            'hardware_options' => ['sometimes', 'nullable', 'array', 'max:10'],
            'hardware_options.*.name' => ['nullable', 'string', 'max:120'],
            'hardware_options.*.price' => ['nullable', 'integer', 'min:0'],
            'hardware_options.*.image' => Media::pathRule(),
            'add_on_slugs' => ['sometimes', 'nullable', 'array'],
            'add_on_slugs.*' => ['string', 'exists:add_ons,slug'],
            'offers_bandwidth' => ['sometimes', 'nullable', 'boolean'],
            'bandwidth_options' => ['sometimes', 'nullable', 'array', 'max:10'],
            'bandwidth_options.*.name' => ['nullable', 'string', 'max:120'],
            'bandwidth_options.*.description' => ['nullable', 'string', 'max:255'],
            'bandwidth_options.*.price' => ['nullable', 'integer', 'min:0'],
            'requires_plan' => ['sometimes', 'boolean'],
        ];
    }

    /** Drop blank hardware rows and duplicate names (the cart matches options by name). */
    private static function cleanCartOptions(array $data): array
    {
        if (array_key_exists('hardware_options', $data)) {
            $data['hardware_options'] = collect($data['hardware_options'] ?? [])
                ->map(fn ($o) => [
                    'name' => trim((string) ($o['name'] ?? '')),
                    'price' => (int) ($o['price'] ?? 0),
                    'image' => ($o['image'] ?? null) ?: null,
                ])
                ->filter(fn ($o) => $o['name'] !== '')
                ->unique('name')
                ->values()
                ->all();
        }
        if (array_key_exists('bandwidth_options', $data)) {
            $data['bandwidth_options'] = BandwidthPackages::cleanProductOptions($data['bandwidth_options']);
        }
        if (array_key_exists('add_on_slugs', $data)) {
            $data['add_on_slugs'] = array_values(array_unique($data['add_on_slugs'] ?? []));
        }

        return $data;
    }
}
