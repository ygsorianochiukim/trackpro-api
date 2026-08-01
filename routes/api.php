<?php

use App\Http\Controllers\Api\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Api\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\Admin\ExpenseController as AdminExpenseController;
use App\Http\Controllers\Api\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Api\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\Admin\ReportsController as AdminReportsController;
use App\Http\Controllers\Api\Admin\SubscriptionController as AdminSubscriptionController;
use App\Http\Controllers\Api\Admin\SubscriptionInvoiceController as AdminSubscriptionInvoiceController;
use App\Http\Controllers\Api\Admin\SubscriptionPlanController as AdminSubscriptionPlanController;
use App\Http\Controllers\Api\CustomerAccountController;
use App\Http\Controllers\Api\CustomerAuthController;
use App\Http\Controllers\Api\CustomerPasswordController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SubscriptionPlanController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public API routes — consumed by the Next.js frontend
|--------------------------------------------------------------------------
*/

// Catalog
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{product:slug}', [ProductController::class, 'show']);

// Published yearly subscription plans (storefront + account UI)
Route::get('/subscription-plans', [SubscriptionPlanController::class, 'index']);

// Customer auth — throttled to blunt credential-stuffing / signup abuse.
Route::post('/customer/register', [CustomerAuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/customer/login', [CustomerAuthController::class, 'login'])->middleware('throttle:10,1');

// Forgot / reset password — tighter throttle, these send email and mutate creds.
Route::post('/customer/password/forgot', [CustomerPasswordController::class, 'forgot'])->middleware('throttle:6,1');
Route::post('/customer/password/reset', [CustomerPasswordController::class, 'reset'])->middleware('throttle:6,1');
// Email verification — clicked from the email (signed URL, no Bearer token).
Route::get('/customer/verify/{id}/{hash}', [CustomerAuthController::class, 'verify'])
    ->middleware('signed')
    ->name('verification.verify');

// PayMongo webhook (no Sanctum — signature verified inside the controller)
Route::post('/paymongo/webhook', [PaymentWebhookController::class, 'paymongo']);

/*
|--------------------------------------------------------------------------
| Authenticated customer routes (Bearer token via Sanctum)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/customer/me', [CustomerAuthController::class, 'me']);
    Route::post('/customer/logout', [CustomerAuthController::class, 'logout']);
    Route::post('/customer/email/resend', [CustomerAuthController::class, 'resendVerification'])->middleware('throttle:6,1');
    Route::get('/customer/orders', [OrderController::class, 'mine']);

    // Orders — require a signed-in (verified) customer; viewing is owner-only.
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{reference}', [OrderController::class, 'show']);
    // TEMPORARY payment stand-in — owner only. Replace with PayMongo before going live.
    Route::post('/orders/{reference}/pay', [OrderController::class, 'payTemporary']);

    /*
    | Account area — dashboard, yearly subscriptions, and billing. Everything is
    | scoped to the signed-in customer inside CustomerAccountController.
    */
    Route::get('/customer/dashboard', [CustomerAccountController::class, 'dashboard']);
    Route::get('/customer/subscriptions', [CustomerAccountController::class, 'subscriptions']);
    Route::post('/customer/subscriptions', [CustomerAccountController::class, 'subscribe']);
    Route::get('/customer/subscriptions/{reference}', [CustomerAccountController::class, 'showSubscription']);
    Route::post('/customer/subscriptions/{reference}/renew', [CustomerAccountController::class, 'renew']);
    Route::post('/customer/subscriptions/{reference}/cancel', [CustomerAccountController::class, 'cancelSubscription']);
    Route::get('/customer/invoices', [CustomerAccountController::class, 'invoices']);
    Route::post('/customer/invoices/{reference}/pay', [CustomerAccountController::class, 'payInvoice']);
});

/*
|--------------------------------------------------------------------------
| Admin API — consumed by the Next.js admin UI (Bearer token + 'admin' ability)
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->group(function () {
    Route::post('/login', [AdminAuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::get('/me', [AdminAuthController::class, 'me']);
        Route::post('/logout', [AdminAuthController::class, 'logout']);

        Route::get('/dashboard', [AdminDashboardController::class, 'index']);

        Route::get('/products', [AdminProductController::class, 'index']);
        Route::post('/products', [AdminProductController::class, 'store']);
        Route::get('/products/{product:id}', [AdminProductController::class, 'show']);
        Route::match(['put', 'patch'], '/products/{product:id}', [AdminProductController::class, 'update']);
        Route::post('/products/{product:id}/stock', [AdminProductController::class, 'adjustStock']);

        Route::get('/orders', [AdminOrderController::class, 'index']);
        Route::get('/orders/{order}', [AdminOrderController::class, 'show']);
        Route::match(['put', 'patch'], '/orders/{order}', [AdminOrderController::class, 'update']);

        Route::get('/payments', [AdminPaymentController::class, 'index']);
        Route::post('/orders/{order}/mark-paid', [AdminPaymentController::class, 'markPaid']);

        // Yearly subscriptions — plans, subscribers, and renewal billing
        Route::get('/subscription-plans', [AdminSubscriptionPlanController::class, 'index']);
        Route::post('/subscription-plans', [AdminSubscriptionPlanController::class, 'store']);
        Route::match(['put', 'patch'], '/subscription-plans/{plan}', [AdminSubscriptionPlanController::class, 'update']);
        Route::delete('/subscription-plans/{plan}', [AdminSubscriptionPlanController::class, 'destroy']);

        Route::get('/subscriptions', [AdminSubscriptionController::class, 'index']);
        Route::post('/subscriptions', [AdminSubscriptionController::class, 'store']);
        Route::get('/subscriptions/{subscription}', [AdminSubscriptionController::class, 'show']);
        Route::match(['put', 'patch'], '/subscriptions/{subscription}', [AdminSubscriptionController::class, 'update']);
        Route::post('/subscriptions/{subscription}/invoice', [AdminSubscriptionController::class, 'generateInvoice']);

        Route::get('/subscription-invoices', [AdminSubscriptionInvoiceController::class, 'index']);
        Route::post('/subscription-invoices/{invoice}/mark-paid', [AdminSubscriptionInvoiceController::class, 'markPaid']);
        Route::post('/subscription-invoices/{invoice}/void', [AdminSubscriptionInvoiceController::class, 'void']);

        // Customer directory (used by the subscription create form)
        Route::get('/customers', [AdminSubscriptionController::class, 'customers']);

        // Sales reports + expenses / cashflow
        Route::get('/reports/sales', [AdminReportsController::class, 'sales']);
        Route::get('/expenses', [AdminExpenseController::class, 'index']);
        Route::post('/expenses', [AdminExpenseController::class, 'store']);
        Route::delete('/expenses/{expense}', [AdminExpenseController::class, 'destroy']);
    });
});
