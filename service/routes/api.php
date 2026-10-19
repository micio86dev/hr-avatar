<?php

use App\Http\Controllers\V1\HealthController;
use Illuminate\Support\Facades\Route;

// All /v1 routes are registered here, grouped by authentication chain.

Route::get('health', [HealthController::class, 'live'])->name('health.live');
Route::get('health/ready', [HealthController::class, 'ready'])->name('health.ready');
