<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Storefront "forgot password" flow. Uses the `customers` password broker
 * (config/auth.php) so its tokens never mix with the admin `users` broker.
 */
class CustomerPasswordController extends Controller
{
    /**
     * POST /api/customer/password/forgot
     *
     * Always answers 200 with the same message — telling an anonymous caller
     * whether an address is registered is an account-enumeration leak.
     */
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:120'],
        ]);

        $status = Password::broker('customers')->sendResetLink($data);

        return response()->json([
            'message' => 'If that email is registered, a password reset link is on its way.',
            'status' => $status, // e.g. passwords.sent / passwords.throttled — for debugging
        ]);
    }

    /** POST /api/customer/password/reset — token + email come from the emailed link. */
    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(6)],
        ]);

        $status = Password::broker('customers')->reset($data, function (Customer $customer, string $password) {
            $customer->forceFill([
                'password' => $password, // hashed by the model cast
                'remember_token' => Str::random(60),
            ])->save();

            // Anyone holding an old Bearer token loses it — a reset should end
            // every existing session, including an attacker's.
            $customer->tokens()->delete();

            event(new PasswordReset($customer));
        });

        if ($status !== Password::PasswordReset) {
            return response()->json([
                'message' => __($status),
                'errors' => ['email' => [__($status)]],
            ], 422);
        }

        // Hand back a fresh token so the frontend can sign the customer straight in.
        $customer = Customer::where('email', $data['email'])->firstOrFail();
        $token = $customer->createToken('customer-token')->plainTextToken;

        return response()->json([
            'message' => 'Your password has been reset.',
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'email_verified' => $customer->hasVerifiedEmail(),
            ],
            'token' => $token,
        ]);
    }
}
