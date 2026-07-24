<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
        if (!$user || !Hash::check($data['password'], $user->password)) {
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
