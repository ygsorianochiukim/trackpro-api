<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Seed the admin login used at /admin.
     *
     * Credentials are read from the environment so they can be set per
     * deployment without editing code. Falls back to safe local-dev defaults.
     *
     *   ADMIN_NAME     — display name
     *   ADMIN_EMAIL    — login email
     *   ADMIN_PASSWORD — login password (auto-hashed by the User cast)
     *
     * Idempotent: re-running updates the existing admin instead of duplicating.
     */
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@trackprogps.com');

        $admin = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => env('ADMIN_NAME', 'TrackPro Admin'),
                'password' => env('ADMIN_PASSWORD', 'trackpro-change-me'),
            ]
        );

        $this->command?->info("Admin user ready: {$admin->email}");
    }
}
