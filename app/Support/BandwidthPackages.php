<?php

namespace App\Support;

use App\Models\Product;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Video bandwidth packages customers can add to a product's platform
 * subscription, e.g. "5 GB / month" at ₱199 per device per month. Kept as one
 * admin-edited list in `settings`.
 */
class BandwidthPackages
{
    private const KEY = 'bandwidth_packages';

    /** Every package, in the admin's order: [{slug, name, description, price, is_active}]. */
    public static function all(): array
    {
        return Setting::get(self::KEY, []);
    }

    /** What customers can pick. */
    public static function active(): array
    {
        return array_values(array_filter(self::all(), fn ($p) => $p['is_active']));
    }

    /**
     * What this product offers: none when bandwidth is switched off for it,
     * its own packages when it has any, otherwise the active defaults.
     * Returns [{slug, name, description, price}].
     */
    public static function for(Product $product): array
    {
        if (!($product->offers_bandwidth ?? true)) {
            return [];
        }
        if ($product->bandwidth_options) {
            return $product->bandwidth_options;
        }

        return array_map(fn ($p) => array_diff_key($p, ['is_active' => true]), self::active());
    }

    /** Admin form rows → stored product packages, each with a stable slug for the cart. */
    public static function cleanProductOptions(?array $rows): array
    {
        $taken = [];
        $out = [];
        foreach ($rows ?? [] as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $slug = Str::slug($name) ?: 'package';
            for ($base = $slug, $i = 2; in_array($slug, $taken, true); $i++) {
                $slug = "{$base}-{$i}";
            }
            $taken[] = $slug;
            $out[] = [
                'slug' => $slug,
                'name' => $name,
                'description' => trim((string) ($row['description'] ?? '')),
                'price' => (int) ($row['price'] ?? 0),
            ];
        }

        return $out;
    }

    /** Replace the list from the admin form; slugs stay stable for packages already in carts. */
    public static function saveFrom(Request $request): array
    {
        $data = $request->validate([
            'packages' => ['present', 'array', 'max:20'],
            'packages.*.slug' => ['nullable', 'string', 'max:60'],
            'packages.*.name' => ['required', 'string', 'max:120'],
            'packages.*.description' => ['nullable', 'string', 'max:255'],
            'packages.*.price' => ['required', 'integer', 'min:0'],
            'packages.*.is_active' => ['required', 'boolean'],
        ]);

        $taken = [];
        $packages = array_map(function ($p) use (&$taken) {
            $slug = Str::slug(($p['slug'] ?? null) ?: $p['name']) ?: 'package';
            for ($base = $slug, $i = 2; in_array($slug, $taken, true); $i++) {
                $slug = "{$base}-{$i}";
            }
            $taken[] = $slug;

            return [
                'slug' => $slug,
                'name' => trim($p['name']),
                'description' => trim($p['description'] ?? ''),
                'price' => (int) $p['price'],
                'is_active' => (bool) $p['is_active'],
            ];
        }, $data['packages']);

        Setting::put(self::KEY, $packages);

        return $packages;
    }
}
