<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Password checking that tolerates hashes made outside Laravel.
 *
 * Laravel's bcrypt hasher verifies the algorithm before comparing, and it does
 * so via `password_get_info()`, which only recognises the `$2y$` prefix. A
 * perfectly valid bcrypt hash written with the `$2a$` prefix — what Node's
 * bcrypt/bcryptjs and many online generators produce, and what arrives in an
 * imported database dump — makes it throw
 * "This password does not use the Bcrypt algorithm", surfacing as a 500 on the
 * login route rather than a failed sign-in.
 *
 * `password_verify()` handles every bcrypt prefix correctly, so we fall back to
 * it and then rewrite the hash in Laravel's own format. The account works on the
 * first attempt and is migrated for every attempt after that.
 */
class PasswordVerifier
{
    /** bcrypt prefixes that `password_verify()` understands. */
    private const BCRYPT_PREFIXES = ['$2y$', '$2a$', '$2b$', '$2x$'];

    /**
     * Verify $plain against the model's stored password, upgrading legacy hashes
     * on success. Returns false for a wrong password or an unusable hash.
     */
    public static function check(Model $user, string $plain, string $column = 'password'): bool
    {
        $stored = (string) ($user->getAttributes()[$column] ?? '');
        if ($stored === '' || $plain === '') {
            return false;
        }

        try {
            return Hash::check($plain, $stored);
        } catch (RuntimeException $e) {
            // Not a hash Laravel's hasher will touch — try the legacy path below.
        }

        if (!self::looksLikeBcrypt($stored) || !password_verify($plain, $stored)) {
            return false;
        }

        // Correct password against a foreign-format hash: rewrite it so every
        // later sign-in takes the normal path. Passing an already-bcrypt value is
        // safe — the model's `hashed` cast detects it and won't hash it twice.
        $user->forceFill([$column => Hash::make($plain)])->saveQuietly();

        return true;
    }

    private static function looksLikeBcrypt(string $hash): bool
    {
        if (strlen($hash) !== 60) {
            return false;
        }

        foreach (self::BCRYPT_PREFIXES as $prefix) {
            if (str_starts_with($hash, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
