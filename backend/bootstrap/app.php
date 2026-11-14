<?php

use App\Http\Middleware\EnableSuperAdminTenantAccess;
use App\Http\Middleware\EnsureActiveMerchantStore;
use App\Http\Middleware\EnsureDashboardAccountIsEligible;
use App\Http\Middleware\EnsureSessionAuthentication;
use App\Http\Middleware\EnsureUserIsNotSoftDeleted;
use App\Http\Middleware\EnsureUserRole;
use App\Http\Middleware\SetTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        // Public bearer-token POST endpoints are stateless: they do not use
        // cookies or the session for authorization. Keep CSRF protection for
        // login and every authenticated Merchant/Super Admin endpoint.
        $middleware->preventRequestForgery(except: [
            'api/v1/public/stores/*/queues',
            'api/v1/public/queues/*/orders',
        ]);

        $middleware->alias([
            'active.user' => EnsureUserIsNotSoftDeleted::class,
            'dashboard.account' => EnsureDashboardAccountIsEligible::class,
            'merchant.store' => EnsureActiveMerchantStore::class,
            'role' => EnsureUserRole::class,
            'session.auth' => EnsureSessionAuthentication::class,
            'tenant.context' => SetTenantContext::class,
            'tenant.platform' => EnableSuperAdminTenantAccess::class,
        ]);
        $middleware->group('dashboard.super_admin', [
            'auth:sanctum',
            'session.auth',
            'active.user',
            'role:super_admin',
            'dashboard.account',
        ]);
        $middleware->group('dashboard.merchant', [
            'auth:sanctum',
            'session.auth',
            'active.user',
            'role:merchant',
            'merchant.store',
            'tenant.context',
        ]);
        foreach ([
            EnsureUserIsNotSoftDeleted::class,
            EnsureSessionAuthentication::class,
            EnsureUserRole::class,
            EnsureActiveMerchantStore::class,
            SetTenantContext::class,
            EnableSuperAdminTenantAccess::class,
        ] as $priorityMiddleware) {
            $middleware->prependToPriorityList(SubstituteBindings::class, $priorityMiddleware);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
