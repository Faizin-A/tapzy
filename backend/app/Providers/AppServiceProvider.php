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
use App\Policies\AuditLogPolicy;
use App\Policies\OrderItemPolicy;
use App\Policies\QueueStatusHistoryPolicy;
use App\Policies\StorePolicy;
use App\Policies\TenantResourcePolicy;
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

        RateLimiter::for('nfc-resolve', function (Request $request): array {
            $token = $request->route('token');
            $tokenHash = is_string($token) ? hash('sha256', $token) : '';
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(60)->by('nfc-resolve-ip:'.$ip),
                Limit::perMinute(30)->by('nfc-resolve-token:'.$tokenHash),
            ];
        });

        // Stage 4.1 — Public store profile (opened once per NFC/QR tap).
        RateLimiter::for('public-store', function (Request $request): array {
            $token = $request->route('token');
            $tokenHash = is_string($token) ? hash('sha256', $token) : '';
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(60)->by('pstore-token:'.$tokenHash.':'.$ip),
                Limit::perMinute(300)->by('pstore-ip:'.$ip),
            ];
        });

        // Stage 4.1 — Public queue-status polling (called every 5-10 seconds).
        // Per-IP-token limit allows one device to poll comfortably at a 5-second
        // interval (12 req/min) with burst headroom. The per-IP cumulative limit
        // protects against flooding through shared public WiFi/NAT while still
        // supporting ~25 simultaneous devices per venue.
        RateLimiter::for('public-queue-status', function (Request $request): array {
            $token = $request->route('token');
            $tokenHash = is_string($token) ? hash('sha256', $token) : '';
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(60)->by('pqs-token:'.$tokenHash.':'.$ip),
                Limit::perMinute(300)->by('pqs-ip:'.$ip),
            ];
        });

        // Stage 4.1 — Public product catalog (triggered on menu open and category filter).
        RateLimiter::for('public-products', function (Request $request): array {
            $token = $request->route('token');
            $tokenHash = is_string($token) ? hash('sha256', $token) : '';
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(30)->by('prod-token:'.$tokenHash.':'.$ip),
                Limit::perMinute(150)->by('prod-ip:'.$ip),
            ];
        });

        // Stage 4.2 — Customer queue creation (state-mutating ticket issuance).
        RateLimiter::for('public-queue-create', function (Request $request): array {
            $token = $request->route('token');
            $tokenHash = is_string($token) ? hash('sha256', $token) : '';
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(5)->by('pqc-token:'.$tokenHash.':'.$ip),
                Limit::perMinute(20)->by('pqc-ip:'.$ip),
            ];
        });

        // Stage 4.3 — Customer queue tracking by access token (read-only polling).
        // The per-token limit supports 5–10 second polling on a single device;
        // the cumulative per-IP limit protects shared public WiFi/NAT.
        RateLimiter::for('public-queue-track', function (Request $request): array {
            $token = $request->route('token');
            $tokenHash = is_string($token) ? hash('sha256', $token) : '';
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(60)->by('pqt-token:'.$tokenHash.':'.$ip),
                Limit::perMinute(300)->by('pqt-ip:'.$ip),
            ];
        });

        // Stage 4.4 — Customer order creation (state-mutating, one order per queue).
        RateLimiter::for('public-order-create', function (Request $request): array {
            $token = $request->route('token');
            $tokenHash = is_string($token) ? hash('sha256', $token) : '';
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(5)->by('poc-token:'.$tokenHash.':'.$ip),
                Limit::perMinute(20)->by('poc-ip:'.$ip),
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
