<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\NfcCard;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Queue;
use App\Models\QueueSession;
use App\Models\QueueStatusHistory;
use App\Models\Store;
use App\Policies\StorePolicy;
use App\Policies\TenantResourcePolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\OrderItemPolicy;
use App\Policies\QueueStatusHistoryPolicy;
use App\Support\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, fn () => new TenantContext);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('auth-login', function (Request $request): array {
            $email = $request->input('email');
            $normalizedEmail = is_string($email) ? Str::lower(trim(substr($email, 0, 255))) : '';
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(5)->by('auth-login:'.$normalizedEmail.':'.$ip),
                Limit::perMinute(30)->by('auth-login-ip:'.$ip),
            ];
        });

        foreach ([
            NfcCard::class,
            Order::class,
            Product::class,
            Queue::class,
            QueueSession::class,
        ] as $model) {
            Gate::policy($model, TenantResourcePolicy::class);
        }

        Gate::policy(Store::class, StorePolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(OrderItem::class, OrderItemPolicy::class);
        Gate::policy(QueueStatusHistory::class, QueueStatusHistoryPolicy::class);
    }
}
