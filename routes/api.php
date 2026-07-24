<?php

use App\Http\Controllers\Api\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Api\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\Admin\ExpenseController as AdminExpenseController;
use App\Http\Controllers\Api\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Api\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\Admin\ReportsController as AdminReportsController;
use App\Http\Controllers\Api\CustomerAuthController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public API routes — consumed by the Next.js frontend
|--------------------------------------------------------------------------
*/

// Catalog
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{product:slug}', [ProductController::class, 'show']);

// Customer auth — throttled to blunt credential-stuffing / signup abuse.
Route::post('/customer/register', [CustomerAuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/customer/login', [CustomerAuthController::class, 'login'])->middleware('throttle:10,1');
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

        // Sales reports + expenses / cashflow
        Route::get('/reports/sales', [AdminReportsController::class, 'sales']);
        Route::get('/expenses', [AdminExpenseController::class, 'index']);
        Route::post('/expenses', [AdminExpenseController::class, 'store']);
        Route::delete('/expenses/{expense}', [AdminExpenseController::class, 'destroy']);
    });
});
