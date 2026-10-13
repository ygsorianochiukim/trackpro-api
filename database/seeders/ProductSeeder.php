<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * The TrackPro hardware lineup (Website Changes Part 3).
     *
     * Older devices keep their original slugs so existing orders and cart links
     * still resolve — only the name, descriptor, price and copy changed:
     *   R12L Pro-X → Core · G21L Premium Anti-Jammer → Guard · S5L 4G Multi-functional → Fleet
     *   S208L Fleet Fuel RFID → Fleet X · W18L Magnet → Go · V7pro Dual Dashcam → Vision Pro
     *   V6 4 Channel Dashcam → Vision X · V5 Pro → Vision · R56L OBD II → Plug
     *   R58L OBD II Diagnosis → Plug X · W20L Solar → Solar · S26L → Link
     *
     * `model` now holds the customer-facing descriptor (e.g. "Anti-Jammer GPS Tracker").
     * `subscription` is the lowest recommended monthly plan for the device.
     * Stock is deliberately absent — it is managed from the admin dashboard.
     */
    public static function lineup(): array
    {
        return [
            [
                'slug' => 'trackpro-core',
                'name' => 'TrackPro Core',
                'model' => 'Standard 4G GPS Tracker',
                'category' => 'Vehicle',
                'icon' => 'map',
                'price' => 5299,
                'subscription' => 299,
                'tagline' => 'Reliable 4G vehicle tracker designed for everyday vehicle security, monitoring, and recovery.',
                'description' => 'Reliable 4G vehicle tracker designed for everyday vehicle security, monitoring, and recovery.',
                'highlights' => ['4G Connectivity', 'Remote Engine Shut-off', 'Vehicle Power Monitoring', 'Vibration Detection'],
                'featured' => false,
            ],
            [
                'slug' => 'trackpro-premium-anti-jammer',
                'name' => 'TrackPro Guard',
                'model' => 'Anti-Jammer GPS Tracker',
                'category' => 'Vehicle',
                'icon' => 'signal',
                'price' => 6799,
                'subscription' => 599,
                'tagline' => 'Advanced anti-theft tracker designed for vehicles requiring enhanced security and recovery protection.',
                'description' => 'Advanced anti-theft tracker designed for vehicles requiring enhanced security and recovery protection.',
                'highlights' => ['Anti-Jamming Protection', 'Remote Door Lock/Unlock', 'Remote Engine/Fuel Cut-Off', 'Advanced Anti-Theft Alerts'],
                'featured' => true,
            ],
            [
                'slug' => 'trackpro-4g-multi-functional',
                'name' => 'TrackPro Fleet',
                'model' => 'Professional Fleet GPS Tracker',
                'category' => 'Fleet',
                'icon' => 'truck',
                'price' => 6299,
                'subscription' => 599,
                'tagline' => 'Professional fleet tracker designed for businesses requiring advanced vehicle, fuel, and temperature monitoring.',
                'description' => 'Professional fleet tracker designed for businesses requiring advanced vehicle, fuel, and temperature monitoring.',
                'highlights' => ['Fuel Monitoring', 'Temperature Monitoring', 'RFID Driver Attendance', 'Remote Fuel/Power Control', 'SOS Function'],
                'featured' => false,
            ],
            [
                'slug' => 'trackpro-fleet-fuel-rfid',
                'name' => 'TrackPro Fleet X',
                'model' => 'Advanced Heavy-Duty Fleet Tracker',
                'category' => 'Fleet',
                'icon' => 'truck',
                'price' => 9199,
                'subscription' => 999,
                'tagline' => 'Advanced fleet tracking solution designed for trucking, logistics, and heavy commercial vehicle operations.',
                'description' => 'Advanced fleet tracking solution designed for trucking, logistics, and heavy commercial vehicle operations.',
                'highlights' => ['Fuel Monitoring', 'Temperature Monitoring', 'RFID Driver Attendance', 'Remote Fuel/Power Control', 'Advanced Fleet Alerts'],
                'featured' => false,
            ],
            [
                'slug' => 'trackpro-magnet-10000',
                'name' => 'TrackPro Go',
                'model' => 'Wireless GPS Tracker',
                'category' => 'Asset',
                'icon' => 'shield',
                'price' => 4699,
                'subscription' => 299,
                'tagline' => 'Battery-powered wireless GPS tracker designed for flexible installation and temporary vehicle or asset monitoring.',
                'description' => 'Battery-powered wireless GPS tracker designed for flexible installation and temporary vehicle or asset monitoring.',
                'highlights' => ['7,500mAh Rechargeable Battery', 'Magnetic Mounting', 'Wireless Installation', 'Remote Audio Monitoring'],
                'featured' => false,
            ],
            [
                'slug' => 'trackpro-vision',
                'name' => 'TrackPro Vision',
                'model' => '4G GPS Dashcam',
                'category' => 'Dashcam',
                'icon' => 'chart',
                'price' => 10999,
                'subscription' => 599,
                'tagline' => '4G GPS dashcam combining vehicle monitoring with remote video recording and surveillance.',
                'description' => '4G GPS dashcam combining vehicle monitoring with remote video recording and surveillance.',
                'highlights' => ['1080P Video Recording', 'Live Video Monitoring', 'Remote Video Playback', 'Remote Photo Capture'],
                'featured' => false,
            ],
            [
                'slug' => 'trackpro-dual-dashcam',
                'name' => 'TrackPro Vision Pro',
                'model' => 'AI Dual-Camera Dashcam',
                'category' => 'Dashcam',
                'icon' => 'chart',
                'price' => 14599,
                'subscription' => 999,
                'tagline' => 'Advanced dual-camera dashcam combining GPS tracking, live video, and AI-powered driver safety monitoring.',
                'description' => 'Advanced dual-camera dashcam combining GPS tracking, live video, and AI-powered driver safety monitoring.',
                'highlights' => [
                    'Dual 1080P Cameras', 'ADAS Driver Assistance', 'DMS Driver Monitoring', 'Live Video Monitoring', 'Two-Way Talk',
                    'Remote Engine Shut-off', 'Fuel Monitoring', 'Temperature Monitoring', 'RFID Driver Attendance',
                ],
                'featured' => false,
            ],
            [
                'slug' => 'trackpro-4-channel-dashcam',
                'name' => 'TrackPro Vision X',
                'model' => 'Multi-Camera Fleet Dashcam',
                'category' => 'Dashcam',
                'icon' => 'chart',
                'price' => 23499,
                'subscription' => 1299,
                'tagline' => 'Professional multi-camera video telematics system designed for comprehensive fleet surveillance and driver safety monitoring.',
                'description' => 'Professional multi-camera video telematics system designed for comprehensive fleet surveillance and driver safety monitoring.',
                'highlights' => [
                    'Up to 4 Cameras', 'Multi-Camera Live Video', 'ADAS Driver Assistance', 'DMS Driver Monitoring', 'Video Event Upload',
                    'Live Video Monitoring', 'Two-Way Talk', 'Fuel Monitoring', 'Temperature Monitoring', 'RFID Driver Attendance',
                ],
                'featured' => false,
            ],
            [
                'slug' => 'trackpro-obd2',
                'name' => 'TrackPro Plug',
                'model' => 'Plug & Play OBD GPS Tracker',
                'category' => 'OBD',
                'icon' => 'power',
                'price' => 3899,
                'subscription' => 299,
                'tagline' => 'Easy-to-install OBD GPS tracker that connects directly to compatible vehicles without conventional hardwired installation.',
                'description' => 'Easy-to-install OBD GPS tracker that connects directly to compatible vehicles without conventional hardwired installation.',
                'highlights' => ['OBD Plug & Play', 'No Hardwire Installation', 'Vehicle Data Monitoring', 'Easy Installation'],
                'featured' => false,
            ],
            [
                'slug' => 'trackpro-obd2-system-diagnosis',
                'name' => 'TrackPro Plug X',
                'model' => 'Advanced OBD GPS Tracker',
                'category' => 'OBD',
                'icon' => 'chart',
                'price' => 5199,
                'subscription' => 299,
                'tagline' => 'Advanced plug-and-play GPS tracker combining vehicle monitoring with available OBD diagnostic information.',
                'description' => 'Advanced plug-and-play GPS tracker combining vehicle monitoring with available OBD diagnostic information.',
                'highlights' => ['OBD Vehicle Diagnostics', 'Plug & Play Installation', 'Vehicle Data Monitoring', 'Driving Behavior Data'],
                'featured' => false,
            ],
            [
                'slug' => 'trackpro-solar-powered',
                'name' => 'TrackPro Solar',
                'model' => 'Solar Asset GPS Tracker',
                'category' => 'Satellite',
                'icon' => 'geo',
                'price' => 8299,
                'subscription' => 999,
                'tagline' => 'Long-term GPS tracking solution designed for trailers, containers, equipment, and assets operating in remote locations.',
                'description' => 'Long-term GPS tracking solution designed for trailers, containers, equipment, and assets operating in remote locations.',
                'highlights' => ['Solar Charging', 'Cellular + Satellite Connectivity', '12,000mAh Battery', 'IP67 Protection', 'Sensor Support'],
                'featured' => false,
            ],
            [
                'slug' => 'trackpro-link',
                'name' => 'TrackPro Link',
                'model' => 'Specialized GPS Tracker',
                'category' => 'Personal',
                'icon' => 'geo',
                'price' => 4899,
                'subscription' => 299,
                'tagline' => 'Flexible GPS tracking solution designed for pet, kids, adult, asset and special vehicle monitoring applications.',
                'description' => 'Flexible GPS tracking solution designed for pet, kids, adult, asset and special vehicle monitoring applications.',
                'highlights' => ['Configurable Sensor Support', 'Specialized Monitoring', 'Custom Installation Options', 'Vehicle & Asset Monitoring'],
                'featured' => false,
            ],
        ];
    }

    public function run(): void
    {
        foreach (self::lineup() as $data) {
            Product::updateOrCreate(['slug' => $data['slug']], $data);
        }
    }
}
