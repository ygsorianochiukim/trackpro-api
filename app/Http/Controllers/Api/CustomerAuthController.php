<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class CustomerAuthController extends Controller
{
    /** Public-safe customer fields + verification state. */
    private function payload(Customer $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'email' => $c->email,
            'phone' => $c->phone,
            'email_verified' => $c->hasVerifiedEmail(),
        ];
    }

    /** POST /api/customer/register */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:120', 'unique:customers,email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        $customer = Customer::create($data);
        $customer->sendEmailVerificationNotification(); // mail driver = log in dev

        $token = $customer->createToken('customer-token')->plainTextToken;

        return response()->json(['customer' => $this->payload($customer), 'token' => $token], 201);
    }

    /** POST /api/customer/login */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $customer = Customer::where('email', $data['email'])->first();
        if (!$customer || !Hash::check($data['password'], $customer->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $customer->createToken('customer-token')->plainTextToken;

        return response()->json(['customer' => $this->payload($customer), 'token' => $token]);
    }

    /** GET /api/customer/me — requires Bearer token */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['customer' => $this->payload($request->user())]);
    }

    /** POST /api/customer/logout — revokes the current token */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Signed out']);
    }

    /** POST /api/customer/email/resend — re-send the verification link (auth) */
    public function resendVerification(Request $request): JsonResponse
    {
        $customer = $request->user();
        if ($customer->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }
        $customer->sendEmailVerificationNotification();
        return response()->json(['message' => 'Verification link sent.']);
    }

    /**
     * GET /api/customer/verify/{id}/{hash} — clicked from the email (signed URL).
     * Marks the email verified, then bounces back to the storefront.
     */
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $customer = Customer::findOrFail($id);

        if (!hash_equals(sha1($customer->getEmailForVerification()), $hash)) {
            abort(403, 'Invalid verification link.');
        }

        if (!$customer->hasVerifiedEmail()) {
            $customer->markEmailAsVerified();
        }

        $frontend = rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/');
        return redirect("{$frontend}/?verified=1");
    }
}
