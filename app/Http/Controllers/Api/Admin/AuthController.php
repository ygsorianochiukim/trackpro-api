<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PasswordVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** POST /api/admin/login — issues a Bearer token with the 'admin' ability. */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();
        // PasswordVerifier, not Hash::check: a bcrypt hash written outside Laravel
        // (a `$2a$` prefix from an imported dump) makes the hasher throw, turning
        // a wrong-password case into a 500. See App\Support\PasswordVerifier.
        if (!$user || !PasswordVerifier::check($user, $data['password'])) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        $token = $user->createToken('admin-token', ['admin'])->plainTextToken;

        return response()->json([
            'admin' => $user->only(['id', 'name', 'email']),
            'token' => $token,
        ]);
    }

    /** GET /api/admin/me */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['admin' => $request->user()->only(['id', 'name', 'email'])]);
    }

    /** POST /api/admin/logout — revokes the current token. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Signed out']);
    }
}
