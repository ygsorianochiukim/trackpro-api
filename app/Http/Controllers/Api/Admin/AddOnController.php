<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AddOn;
use App\Support\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AddOnController extends Controller
{
    /** GET /api/admin/add-ons — all add-ons, including inactive ones. */
    public function index(): JsonResponse
    {
        return response()->json(['data' => AddOn::orderBy('sort_order')->orderBy('id')->get()]);
    }

    /** POST /api/admin/add-ons */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($data['name']);
        $data['sort_order'] ??= (int) AddOn::max('sort_order') + 1;

        $addOn = AddOn::create($data);

        return response()->json(['data' => $addOn->fresh()], 201);
    }

    /** PUT|PATCH /api/admin/add-ons/{addOn} */
    public function update(Request $request, AddOn $addOn): JsonResponse
    {
        $addOn->update($this->validated($request, $addOn));

        return response()->json(['data' => $addOn->fresh()]);
    }

    /** DELETE /api/admin/add-ons/{addOn} — nothing references add-ons, so a hard delete is safe. */
    public function destroy(AddOn $addOn): JsonResponse
    {
        $addOn->delete();

        return response()->json(['message' => 'Add-on deleted.']);
    }

    private function validated(Request $request, ?AddOn $addOn = null): array
    {
        $data = $request->validate([
            'name' => [$addOn ? 'sometimes' : 'required', 'string', 'max:120'],
            'subtitle' => ['nullable', 'string', 'max:120'],
            'price' => [$addOn ? 'sometimes' : 'required', 'integer', 'min:0'],
            'icon' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:1000'],
            'features' => ['nullable', 'array'],
            'features.*' => ['nullable', 'string', 'max:160'],
            'devices' => ['nullable', 'array'],
            'devices.*' => ['nullable', 'string', 'max:120'],
            'warranty' => ['nullable', 'string', 'max:120'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            ...Media::rules(),
        ]);

        // Blank order = leave it alone (store() then appends the add-on last).
        if (($data['sort_order'] ?? null) === null) {
            unset($data['sort_order']);
        }

        foreach (['features', 'devices'] as $list) {
            if (array_key_exists($list, $data)) {
                $data[$list] = array_values(array_filter(array_map(fn ($v) => trim((string) $v), $data[$list] ?? [])));
            }
        }

        return $data;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'add-on';
        $slug = $base;
        $i = 2;
        while (AddOn::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
