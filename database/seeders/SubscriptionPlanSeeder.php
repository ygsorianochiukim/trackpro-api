<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    /**
     * Starter yearly plans. Prices are per device, per year, in whole pesos —
     * edit them from Admin → Subscriptions → Plans rather than here.
     */
    public function run(): void
    {
        $plans = [
            [
                'slug' => 'basic-yearly',
                'name' => 'Basic Tracking (Yearly)',
                'description' => 'Real-time GPS tracking and history for one device, billed once a year.',
                'price' => 1200,
                'billing_period' => 'yearly',
                'features' => [
                    'Real-time location tracking',
                    '30-day travel history',
                    'Mobile app access',
                    'Email support',
                ],
                'is_default' => true,
                'is_active' => true,
            ],
            [
                'slug' => 'premium-yearly',
                'name' => 'Premium Anti-Jammer (Yearly)',
                'description' => 'Everything in Basic plus anti-jammer alerts, remote engine kill, and door lock control.',
                'price' => 1800,
                'billing_period' => 'yearly',
                'features' => [
                    'Everything in Basic',
                    'Anti-jammer detection & alerts',
                    'Remote engine kill / restore',
                    'Remote door lock & unlock',
                    '1-year travel history',
                    'Priority phone support',
                ],
                'is_default' => false,
                'is_active' => true,
            ],
            [
                'slug' => 'fleet-yearly',
                'name' => 'Fleet (Yearly, per unit)',
                'description' => 'Premium features for fleets, with per-unit yearly billing and a shared dashboard.',
                'price' => 1500,
                'billing_period' => 'yearly',
                'features' => [
                    'Everything in Premium',
                    'Multi-vehicle dashboard',
                    'Driver behaviour reports',
                    'Geofence alerts',
                    'Dedicated account manager',
                ],
                'is_default' => false,
                'is_active' => true,
            ],
        ];

        foreach ($plans as $plan) {
            SubscriptionPlan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
