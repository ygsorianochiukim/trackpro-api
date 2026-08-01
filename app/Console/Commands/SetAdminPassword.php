<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Reset (or create) the admin login without going near SQL.
 *
 *   php artisan admin:password
 *   php artisan admin:password --email=admin@trackprogps.com --password='…'
 *
 * Also repairs an account whose stored hash came from somewhere other than
 * Laravel — a `$2a$`-prefixed bcrypt hash from an imported dump makes the
 * hasher throw instead of simply failing the comparison.
 */
class SetAdminPassword extends Command
{
    protected $signature = 'admin:password
                            {--email= : Admin email (defaults to ADMIN_EMAIL)}
                            {--password= : New password (prompted for when omitted)}
                            {--show-hash : Print the stored hash format, changing nothing}';

    protected $description = 'Set the admin password, or inspect the stored hash format';

    public function handle(): int
    {
        $email = $this->option('email') ?: env('ADMIN_EMAIL', 'admin@trackprogps.com');
        $user = User::where('email', $email)->first();

        if ($this->option('show-hash')) {
            if (!$user) {
                $this->error("No admin found for {$email}.");
                return self::FAILURE;
            }

            $hash = (string) ($user->getAttributes()['password'] ?? '');
            $info = password_get_info($hash);
            $this->line("email:  {$email}");
            $this->line('prefix: ' . substr($hash, 0, 7) . '  (length ' . strlen($hash) . ')');
            $this->line('algo:   ' . ($info['algoName'] ?? 'unknown'));

            if (($info['algoName'] ?? '') !== 'bcrypt') {
                $this->warn('This hash is not one Laravel will verify directly. Sign-in still works '
                    . '(it falls back and re-hashes on success), but running this command without '
                    . '--show-hash rewrites it cleanly.');
            } else {
                $this->info('Hash format is fine.');
            }

            return self::SUCCESS;
        }

        $password = $this->option('password') ?: $this->secret('New admin password (min 8 chars)');

        if (!is_string($password) || strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');
            return self::FAILURE;
        }

        if ($user) {
            // forceFill + saveQuietly so the `hashed` cast can't double-handle it.
            $user->forceFill(['password' => Hash::make($password)])->saveQuietly();
            $this->info("Password updated for {$email}.");
        } else {
            $user = new User();
            $user->forceFill([
                'name' => env('ADMIN_NAME', 'TrackPro Admin'),
                'email' => $email,
                'password' => Hash::make($password),
            ])->saveQuietly();
            $this->info("Admin created: {$email}");
        }

        // Any token issued under the old password should stop working.
        $revoked = $user->tokens()->delete();
        if ($revoked) {
            $this->line("Revoked {$revoked} existing admin token(s) — sign in again.");
        }

        return self::SUCCESS;
    }
}
