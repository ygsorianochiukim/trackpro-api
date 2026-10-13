<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    /** Yearly plans from before the homepage plans moved into the database. */
    public const LEGACY_SLUGS = ['basic-yearly', 'premium-yearly', 'fleet-yearly'];

    /**
     * The plan lineup — the single source for the homepage Subscription Plan
     * section, the customer account sign-up, and billing. Edit plans from
     * Admin → Subscriptions → Plans rather than here.
     *
     * `comparison` rows feed the homepage comparison table: `true` = ✓,
     * `false` = —, a string is shown as-is.
     */
    public static function lineup(): array
    {
        $core = ['GPS Tracking Platform', 'Web Dashboard', 'Mobile App', 'Real-Time Tracking', 'Trip History',
            'Geo-fencing', 'Speed Monitoring', 'Standard Reports', 'Platform Updates'];
        $standard = ['Data Plan Packages', 'Video Surveillance', 'Temperature Monitoring', 'Fuel Monitoring',
            'RFID Driver Scan-in', 'Multi-Device Management'];
        $premium = ['Custom Report', 'Driver Management', 'Regional Management', 'Check-in Management'];
        $enterprise = ['Task Management'];
        $enterpriseExtras = ['Account Manager', 'Multi-Branch Fleet Management', 'Service Level Agreement', 'API Integration Assistance'];

        // Tier 1–4 → comparison rows, in the homepage table's order.
        $comparison = function (int $tier, string $support, string $training) use ($core, $standard, $premium, $enterprise, $enterpriseExtras) {
            $rows = [];
            foreach ([[$core, 1], [$standard, 2], [$premium, 3], [$enterprise, 4]] as [$features, $from]) {
                foreach ($features as $f) {
                    $rows[] = ['feature' => $f, 'value' => $tier >= $from];
                }
            }
            $rows[] = ['feature' => 'Technical Support', 'value' => $support];
            $rows[] = ['feature' => 'Customer Support', 'value' => $support];
            $rows[] = ['feature' => 'User Training', 'value' => $training];
            foreach ($enterpriseExtras as $f) {
                $rows[] = ['feature' => $f, 'value' => $tier >= 4];
            }

            return $rows;
        };

        return [
            [
                'slug' => 'basic',
                'name' => 'Basic',
                'price' => 299,
                'fleet_size' => '1–2 Vehicles',
                'description' => 'Essential GPS tracking for personal and everyday vehicle use.',
                'features' => [
                    'GPS Tracking Platform', 'Web Dashboard', 'Mobile App', 'Real-Time Tracking', 'Trip History', 'Geo-Fencing',
                    'Speed Monitoring', 'Standard Reports', 'Platform Updates', 'Business-Hours Technical Support',
                    'Business-Hours Customer Support', 'Initial User Training',
                ],
                'recommended_for' => ['Private Vehicles', 'Motorcycles', 'Family Vehicles', 'Individual Users'],
                'comparison' => $comparison(1, 'Business-Hours', 'Initial Training'),
                'is_popular' => false,
                'is_default' => true,
            ],
            [
                'slug' => 'standard',
                'name' => 'Standard',
                'price' => 599,
                'fleet_size' => '3–10 Vehicles',
                'description' => 'Enhanced vehicle monitoring for rental businesses and small fleets.',
                'features' => [
                    'Everything in Basic', 'Video Surveillance', 'Temperature Monitoring', 'Fuel Monitoring', 'RFID Driver Scan-in',
                    'Multi-Device Management', 'Data Plan Packages', 'Refresher User Training',
                ],
                'recommended_for' => ['Car Rental', 'Small Fleets', 'Delivery Vehicles', 'Company Vehicles'],
                'comparison' => $comparison(2, 'Business-Hours', 'Refresher'),
                'is_popular' => false,
                'is_default' => false,
            ],
            [
                'slug' => 'premium',
                'name' => 'Premium',
                'price' => 999,
                'fleet_size' => '11–30 Vehicles',
                'description' => 'Advanced fleet management for growing businesses and professional operators.',
                'features' => [
                    'Everything in Standard', 'Custom Reports', 'Driver Management', 'Regional Management', 'Check-in Management',
                    'Advanced Fleet Management', 'Priority Technical Support', 'Priority Customer Support', 'Advanced User Training',
                ],
                'recommended_for' => ['Trucking', 'Logistics', 'Delivery Fleets', 'Corporate Fleets', 'Growing Businesses'],
                'comparison' => $comparison(3, 'Priority', 'Advanced'),
                'is_popular' => true,
                'is_default' => false,
            ],
            [
                'slug' => 'enterprise',
                'name' => 'Enterprise',
                'price' => 1299,
                'fleet_size' => '31+ Vehicles',
                'description' => 'Complete fleet management for large organizations and multi-branch operations.',
                'features' => [
                    'Everything in Premium', 'Task Management', 'Dedicated Technical Support', 'Dedicated Customer Support',
                    'Custom User Training', 'Dedicated Account Manager', 'Multi-Branch Fleet Management', 'Service Level Agreement',
                    'API Integration Assistance',
                ],
                'recommended_for' => ['Large Fleets', 'Trucking Companies', 'Logistics Companies', 'Transport Companies', 'Enterprises'],
                'comparison' => $comparison(4, 'Dedicated', 'Custom Training'),
                'is_popular' => false,
                'is_default' => false,
            ],
        ];
    }

    /**
     * Idempotent: plans that already exist (matched by slug) keep whatever the
     * admin set. Retires the legacy yearly plans from sale — existing
     * subscribers keep them, they just can't be chosen any more.
     */
    public function run(): void
    {
        foreach (static::lineup() as $i => $plan) {
            SubscriptionPlan::firstOrCreate(['slug' => $plan['slug']], $plan + [
                'billing_period' => 'monthly',
                'is_active' => true,
                'sort_order' => $i + 1,
            ]);
        }

        SubscriptionPlan::whereIn('slug', self::LEGACY_SLUGS)->update(['is_active' => false, 'is_default' => false]);

        // Exactly one default, and it must be on sale.
        if (!SubscriptionPlan::where('is_default', true)->where('is_active', true)->exists()) {
            SubscriptionPlan::where('slug', 'basic')->update(['is_default' => true]);
        }
    }
}
