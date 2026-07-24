<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
| This is an API-only backend. All UI (storefront + admin dashboard) lives in
| the Next.js app and talks to /api/*. The legacy Blade admin has been removed.
| The root just points humans at the frontend.
*/

Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'status' => 'ok',
    'frontend' => env('FRONTEND_URL'),
]));
