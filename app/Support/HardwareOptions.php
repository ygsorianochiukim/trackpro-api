<?php

namespace App\Support;

use App\Models\Product;
use App\Models\Setting;
use Illuminate\Http\Request;

/**
 * Hardware choices in the add-to-cart options. A product with its own list
 * uses it; every other product gets the store-wide defaults (e.g. Device /
 * Device + IoT / Device + IoT + Installation), priced as the product's price
 * plus each default's extra charge. Each option may have an image (an
 * uploaded path, served as `image_url`).
 */
class HardwareOptions
{
    private const KEY = 'default_hardware_options';

    /** Store-wide defaults: [{name, extra, image, image_url}]. */
    public static function defaults(): array
    {
        return self::withImageUrls(Setting::get(self::KEY, []));
    }

    public static function saveDefaultsFrom(Request $request): array
    {
        $data = $request->validate([
            'options' => ['present', 'array', 'max:10'],
            'options.*.name' => ['nullable', 'string', 'max:120'],
            'options.*.extra' => ['nullable', 'integer', 'min:0'],
            'options.*.image' => Media::pathRule(),
        ]);

        $options = collect($data['options'])
            ->map(fn ($o) => [
                'name' => trim((string) ($o['name'] ?? '')),
                'extra' => (int) ($o['extra'] ?? 0),
                'image' => ($o['image'] ?? null) ?: null,
            ])
            ->filter(fn ($o) => $o['name'] !== '')
            ->unique('name')
            ->values()
            ->all();
        Setting::put(self::KEY, $options);

        return self::defaults();
    }

    /** What the customer picks from for this product: [{name, price, image, image_url}] (empty = base price only). */
    public static function for(Product $product): array
    {
        if ($product->hardware_options) {
            return $product->hardware_options;
        }

        return array_map(
            fn ($o) => [
                'name' => $o['name'],
                'price' => $product->price + $o['extra'],
                'image' => $o['image'] ?? null,
                'image_url' => $o['image_url'],
            ],
            self::defaults(),
        );
    }

    /** Add `image_url` for each option's uploaded image (null when it has none). */
    public static function withImageUrls(array $options): array
    {
        return array_map(
            fn (array $o) => $o + ['image_url' => !empty($o['image']) ? Media::url($o['image']) : null],
            $options,
        );
    }
}
