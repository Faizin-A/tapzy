<?php

use App\Http\Controllers\Api\V1\Admin\NfcCardController;
use App\Http\Controllers\Api\V1\Admin\StoreController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Merchant\NfcCardController as MerchantNfcCardController;
use App\Http\Controllers\Api\V1\Merchant\DashboardController as MerchantDashboardController;
use App\Http\Controllers\Api\V1\Merchant\ProductController as MerchantProductController;
use App\Http\Controllers\Api\V1\Merchant\QueueController as MerchantQueueController;
use App\Http\Controllers\Api\V1\Merchant\QueueSessionController as MerchantQueueSessionController;
use App\Http\Controllers\Api\V1\PublicCustomer\PublicOrderController;
use App\Http\Controllers\Api\V1\PublicCustomer\PublicQueueTrackingController;
use App\Http\Controllers\Api\V1\PublicCustomer\PublicStoreController;
use App\Http\Controllers\Api\V1\PublicNfc\NfcResolutionController;
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

    // Stage 3 — untouched: NFC/QR handshake, updates last_used_at.
    Route::middleware('throttle:nfc-resolve')
        ->get('/public/nfc/{token}', [NfcResolutionController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{40}');

    // Stage 4.1 — Public customer endpoints (read-only, no last_used_at update).
    Route::prefix('public/stores/{token}')
        ->where(['token' => '[A-Za-z0-9]{40}'])
        ->group(function () {
            Route::middleware('throttle:public-store')
                ->get('', [PublicStoreController::class, 'show']);

            Route::middleware('throttle:public-queue-status')
                ->get('/queue-status', [PublicStoreController::class, 'queueStatus']);

            Route::middleware('throttle:public-products')
                ->get('/products', [PublicStoreController::class, 'products']);

            // Stage 4.2 — Customer queue creation.
            Route::middleware('throttle:public-queue-create')
                ->post('/queues', [PublicStoreController::class, 'storeQueue']);
        });

    // Stage 4.3 — Customer queue tracking by access token.
    Route::middleware('throttle:public-queue-track')
        ->get('/public/queues/{token}', [PublicQueueTrackingController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{40}');

    // Stage 4.4 — Customer order creation by queue access token.
    Route::middleware('throttle:public-order-create')
        ->post('/public/queues/{token}/orders', [PublicOrderController::class, 'store'])
        ->where('token', '[A-Za-z0-9]{40}');

    Route::middleware(['auth:sanctum', 'session.auth', 'active.user'])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me'])->middleware('dashboard.account');
    });

    Route::middleware(['dashboard.super_admin', 'tenant.platform'])
        ->prefix('admin')
        ->group(function () {
            Route::get('/stores/expiry-alerts', [StoreController::class, 'expiryAlerts']);
            Route::get('/stores', [StoreController::class, 'index']);
            Route::post('/stores', [StoreController::class, 'store']);
            Route::get('/nfc-cards', [NfcCardController::class, 'index']);
            Route::post('/stores/{store}/nfc-cards', [NfcCardController::class, 'store'])->whereNumber('store');
            Route::get('/nfc-cards/{nfcCard}', [NfcCardController::class, 'show'])->whereNumber('nfcCard');
            Route::patch('/nfc-cards/{nfcCard}', [NfcCardController::class, 'update'])->whereNumber('nfcCard');
            Route::patch('/nfc-cards/{nfcCard}/status', [NfcCardController::class, 'updateStatus'])->whereNumber('nfcCard');
            Route::delete('/nfc-cards/{nfcCard}', [NfcCardController::class, 'destroy'])->whereNumber('nfcCard');
            Route::get('/stores/{store}', [StoreController::class, 'show'])->whereNumber('store');
            Route::patch('/stores/{store}', [StoreController::class, 'update'])->whereNumber('store');
            Route::patch('/stores/{store}/status', [StoreController::class, 'updateStatus'])->whereNumber('store');
            Route::patch('/stores/{store}/expiry', [StoreController::class, 'updateExpiry'])->whereNumber('store');
            Route::delete('/stores/{store}', [StoreController::class, 'destroy'])->whereNumber('store');
        });

    Route::middleware('dashboard.merchant')
        ->prefix('merchant')
        ->group(function () {
            Route::get('/dashboard', [MerchantDashboardController::class, 'dashboard']);
            Route::get('/profile', [MerchantDashboardController::class, 'profile']);
            Route::patch('/profile', [MerchantDashboardController::class, 'updateProfile']);
            Route::get('/queue-session', [MerchantQueueSessionController::class, 'show']);
            Route::post('/queue-session', [MerchantQueueSessionController::class, 'open']);
            Route::patch('/queue-session/close', [MerchantQueueSessionController::class, 'close']);
            Route::post('/queue-session/reset', [MerchantQueueSessionController::class, 'reset']);
            Route::get('/queues', [MerchantQueueController::class, 'index']);
            Route::post('/queues/manual', [MerchantQueueController::class, 'storeManual']);
            Route::post('/queues/next/call', [MerchantQueueController::class, 'callNext']);
            Route::get('/queues/{queue}', [MerchantQueueController::class, 'show'])->whereNumber('queue');
            Route::post('/queues/{queue}/call', [MerchantQueueController::class, 'call'])->whereNumber('queue');
            Route::post('/queues/{queue}/start', [MerchantQueueController::class, 'start'])->whereNumber('queue');
            Route::post('/queues/{queue}/skip', [MerchantQueueController::class, 'skip'])->whereNumber('queue');
            Route::post('/queues/{queue}/cancel', [MerchantQueueController::class, 'cancel'])->whereNumber('queue');
            Route::post('/queues/{queue}/complete', [MerchantQueueController::class, 'complete'])->whereNumber('queue');
            Route::get('/products', [MerchantProductController::class, 'index']);
            Route::post('/products', [MerchantProductController::class, 'store']);
            Route::get('/products/{product}', [MerchantProductController::class, 'show'])->whereNumber('product');
            Route::patch('/products/{product}', [MerchantProductController::class, 'update'])->whereNumber('product');
            Route::delete('/products/{product}', [MerchantProductController::class, 'destroy'])->whereNumber('product');
            Route::patch('/products/{product}/status', [MerchantProductController::class, 'status'])->whereNumber('product');
            Route::get('/nfc-cards', [MerchantNfcCardController::class, 'index']);
            Route::get('/nfc-cards/{nfcCard}', [MerchantNfcCardController::class, 'show'])->whereNumber('nfcCard');
        });
});

Route::get('/user', [AuthController::class, 'me'])
    ->middleware(['auth:sanctum', 'session.auth', 'active.user', 'dashboard.account']);
