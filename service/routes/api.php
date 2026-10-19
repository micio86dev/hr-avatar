<?php

use App\Http\Controllers\V1\ApiKeyController;
use App\Http\Controllers\V1\HealthController;
use App\Http\Middleware\RejectKeyInQuery;
use Illuminate\Support\Facades\Route;

// All /v1 routes are registered here, grouped by authentication chain.

Route::get('health', [HealthController::class, 'live'])->name('health.live');
Route::get('health/ready', [HealthController::class, 'ready'])->name('health.ready');

// Secret-key routes: the key is read from the Authorization header only, then rate limited per key.
Route::middleware([RejectKeyInQuery::class, 'auth.secret', 'throttle:secret'])->group(function () {
    Route::middleware('scope:api_keys:manage')->group(function () {
        Route::get('api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
        Route::post('api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
        Route::post('api-keys/{apiKey}/revoke', [ApiKeyController::class, 'revoke'])->name('api-keys.revoke');
        Route::post('api-keys/{apiKey}/rotate', [ApiKeyController::class, 'rotate'])->name('api-keys.rotate');
    });
});
