<?php

namespace App\Support;

use App\Models\AddOn;
use App\Models\Product;
use App\Models\SubscriptionPlan;

/**
 * Prices one cart line from what the customer picked for a product: hardware
 * option (required when the product has options), add-ons, video bandwidth and
 * platform subscription (required when the product needs one). Everything is
 * looked up again here — prices sent by the browser are never trusted.
 */
class CartPricing
{
    /**
     * @param  array{hardware?: ?string, add_ons?: ?array, bandwidth?: ?string, plan?: ?string}  $picked
     * @return array{unit_price: int, unit_subscription: int, line_total: int, plan_total: int, options: ?array}
     */
    public static function line(Product $product, array $picked, int $qty): array
    {
        $name = $product->name;

        // Hardware — the option's price replaces the product's base price.
        $hardware = null;
        $unit = $product->price;
        $choices = HardwareOptions::for($product);
        if ($choices) {
            $hardware = collect($choices)->firstWhere('name', $picked['hardware'] ?? null);
            if (!$hardware) {
                abort(422, "Choose a hardware option for {$name}.");
            }
            $hardware = ['name' => $hardware['name'], 'price' => (int) $hardware['price']];
            $unit = $hardware['price'];
        }

        // Add-ons — one-time, only those offered with this product.
        $addOnSlugs = array_values(array_unique($picked['add_ons'] ?? []));
        $addOns = [];
        if ($addOnSlugs) {
            // Never configured (null) = every active add-on is offered; [] = none.
            $offered = $product->add_on_slugs;
            $found = AddOn::where('is_active', true)->whereIn('slug', $addOnSlugs)->get()->keyBy('slug');
            foreach ($addOnSlugs as $slug) {
                $addOn = $found->get($slug);
                if (!$addOn || ($offered !== null && !in_array($slug, $offered, true))) {
                    abort(422, "That add-on is no longer available for {$name}.");
                }
                $addOns[] = ['slug' => $addOn->slug, 'name' => $addOn->name, 'price' => $addOn->price];
                $unit += $addOn->price;
            }
        }

        // Platform subscription — its first period is paid with the order.
        $plan = null;
        if ($product->requires_plan) {
            $model = SubscriptionPlan::where('is_active', true)->where('slug', $picked['plan'] ?? null)->first();
            if (!$model) {
                abort(422, "Choose a platform subscription for {$name}.");
            }
            $plan = [
                'slug' => $model->slug,
                'name' => $model->name,
                'price' => $model->price,
                'billing_period' => $model->billing_period,
            ];
        }

        // Video bandwidth — monthly, billed with the plan for each of its months.
        $bandwidth = null;
        if (!empty($picked['bandwidth'])) {
            // The product's own packages, or the defaults when it declares none.
            $package = $plan ? collect(BandwidthPackages::for($product))->firstWhere('slug', $picked['bandwidth']) : null;
            if (!$package) {
                abort(422, "That video bandwidth package is no longer available for {$name}.");
            }
            $bandwidth = ['slug' => $package['slug'], 'name' => $package['name'], 'price' => $package['price']];
        }

        $planUnit = 0;
        if ($plan) {
            $months = $plan['billing_period'] === 'yearly' ? 12 : 1;
            $planUnit = $plan['price'] + ($bandwidth['price'] ?? 0) * $months;
        }

        $chosen = $hardware || $addOns || $plan || $bandwidth;

        return [
            'unit_price' => $unit,
            // A chosen plan replaces the product's legacy built-in monthly fee.
            'unit_subscription' => $plan ? 0 : $product->subscription,
            'line_total' => $unit * $qty,
            'plan_total' => $planUnit * $qty,
            'options' => $chosen ? [
                'hardware' => $hardware,
                'add_ons' => $addOns,
                'plan' => $plan,
                'bandwidth' => $bandwidth,
                'plan_unit' => $planUnit,
            ] : null,
        ];
    }
}
