<?php

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', function () {
        return response()->json([
            'success' => true,
            'message' => 'TAPZY API is running',
            'data' => [
                'application' => config('app.name', 'TAPZY'),
                'status' => 'healthy',
            ],
        ]);
    });

    Route::middleware('throttle:auth-login')->post('/auth/login', [AuthController::class, 'login']);

    Route::middleware(['auth:sanctum', 'session.auth', 'active.user'])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me'])->middleware('dashboard.account');
    });
});

Route::get('/user', [AuthController::class, 'me'])
    ->middleware(['auth:sanctum', 'session.auth', 'active.user', 'dashboard.account']);
