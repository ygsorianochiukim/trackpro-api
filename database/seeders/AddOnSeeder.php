<?php

namespace Database\Seeders;

use App\Models\AddOn;
use Illuminate\Database\Seeder;

class AddOnSeeder extends Seeder
{
    /**
     * The starter "Optional Add-ons" lineup shown on the homepage. Edit, add or
     * remove add-ons from Admin → Add-ons rather than here.
     */
    public static function lineup(): array
    {
        $fleet = ['TrackPro Fleet', 'TrackPro Fleet X', 'TrackPro Vision Pro', 'TrackPro Vision X', 'TrackPro Solar'];

        return [
            [
                'slug' => 'fuel-sensor',
                'name' => 'Fuel Sensor',
                'subtitle' => 'Capacitive Fuel Sensor',
                'price' => 5450,
                'icon' => 'chart',
                'description' => 'Monitor vehicle fuel levels to help detect unusual fuel consumption, refueling, and possible fuel theft.',
                'features' => ['Real-Time Fuel Level Monitoring', 'Fuel Consumption Tracking', 'Fuel Theft Detection', 'Fuel Usage Reports'],
                'devices' => $fleet,
            ],
            [
                'slug' => 'temperature-sensor',
                'name' => 'Temperature Sensor',
                'subtitle' => 'Temperature & Humidity',
                'price' => 1650,
                'icon' => 'chart',
                'description' => 'Monitor vehicle or cargo temperature for temperature-sensitive goods and refrigerated transportation.',
                'features' => ['Real-Time Temperature Monitoring', 'Temperature Alerts', 'Temperature History', 'Cold-Chain Monitoring'],
                'devices' => $fleet,
            ],
            [
                'slug' => 'rfid-driver-id',
                'name' => 'RFID Driver ID',
                'subtitle' => 'Driver’s Attendance',
                'price' => 1750,
                'icon' => 'check',
                'description' => 'Identify drivers using RFID for easier driver assignment, vehicle access, and trip accountability.',
                'features' => ['RFID Driver Identification', 'Driver-to-Vehicle Assignment', 'Driver Activity Records', 'Easy Driver Scan-In'],
                'devices' => $fleet,
            ],
            [
                'slug' => 'sos-button',
                'name' => 'SOS Button',
                'subtitle' => 'Emergency Alert',
                'price' => 700,
                'icon' => 'support',
                'description' => 'Provides drivers with a dedicated emergency button to quickly send an SOS alert when assistance is needed.',
                'features' => ['One-Touch SOS Alert', 'Emergency Notification', 'Driver Safety Support', 'Easy Access Emergency Button'],
                'devices' => ['TrackPro Core', 'TrackPro Guard', 'TrackPro Fleet', 'TrackPro Fleet X', 'TrackPro Solar'],
            ],
            [
                'slug' => 'engine-shut-off-relay',
                'name' => 'Engine Shut-off Relay',
                'subtitle' => 'Emergency Shut-down',
                'price' => 1800,
                'icon' => 'power',
                'description' => 'Allows authorized users to remotely disable the vehicle engine through the TrackPro platform for enhanced security and recovery.',
                'features' => ['Remote Engine Shut-Off', 'Anti-Theft Protection', 'Authorized Remote Control', 'Vehicle Recovery Support'],
                'devices' => ['TrackPro Core', 'TrackPro Guard', 'TrackPro Fleet', 'TrackPro Fleet X', 'TrackPro Solar', 'TrackPro Vision Pro', 'TrackPro Vision X'],
            ],
            [
                'slug' => 'voice-monitoring',
                'name' => 'Voice Monitoring',
                'subtitle' => 'Remote Microphone',
                'price' => 800,
                'icon' => 'signal',
                'description' => 'Adds remote voice monitoring capability for compatible TrackPro devices, providing an additional layer of security and monitoring.',
                'features' => ['Remote Voice Monitoring', 'Audio Listening', 'Remote Access'],
                'devices' => ['TrackPro Guard', 'TrackPro Fleet', 'TrackPro Fleet X', 'TrackPro Solar'],
            ],
            [
                'slug' => 'satellite-module',
                'name' => 'Satellite Module',
                'subtitle' => 'Satellite Signal',
                'price' => 11680,
                'icon' => 'geo',
                'description' => 'Extends tracking capability to remote areas where cellular connectivity may be unavailable or unreliable.',
                'features' => ['Satellite Connectivity', 'Remote-Area Tracking', 'Cellular + Satellite Communication', 'Extended Asset Coverage'],
                'devices' => ['TrackPro Solar'],
            ],
            [
                'slug' => 'camera-dome',
                'name' => 'Camera Dome',
                'subtitle' => 'Additional Camera',
                'price' => 2562,
                'icon' => 'shield',
                'description' => 'Adds an additional interior or auxiliary camera for expanded vehicle visibility and surveillance.',
                'features' => ['Additional Camera View', 'Interior Monitoring', 'Expanded Video Coverage', 'Compatible with Multi-Camera Systems'],
                'devices' => ['TrackPro Fleet', 'TrackPro Fleet X', 'TrackPro Vision Pro', 'TrackPro Vision X'],
            ],
        ];
    }

    /** Idempotent: add-ons that already exist (matched by slug) keep whatever the admin set. */
    public function run(): void
    {
        foreach (static::lineup() as $i => $data) {
            AddOn::firstOrCreate(['slug' => $data['slug']], $data + ['sort_order' => $i + 1]);
        }
    }
}
