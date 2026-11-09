<?php

namespace Tests\Feature\Admin;

use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StoreExpiryEnforcementTest extends TestCase
{
    private const SPA_ORIGIN = 'http://localhost:3000';

    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());

        config(['session.driver' => 'array']);
        config(['sanctum.stateful' => ['localhost:3000']]);

        foreach (['sessions', 'personal_access_tokens', 'products', 'users', 'stores'] as $table) {
            Schema::dropIfExists($table);
        }

        $this->createSchema();
        $this->registerTestRoutes();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_expired_store_is_rejected_at_login_and_on_merchant_requests(): void
    {
        $store = $this->createStore(expiresAt: now()->addDay());
        $merchant = $this->createMerchant($store);

        $this->login($merchant)->assertOk();
        $this->withSpaOrigin()->getJson('/api/v1/testing/merchant/context')->assertOk();

        $store->expires_at = now()->subMinute();
        $store->save();

        $this->withSpaOrigin()->getJson('/api/v1/testing/merchant/context')->assertForbidden();
        $this->withSpaOrigin()->getJson('/api/v1/auth/me')->assertForbidden();

        $this->withSpaOrigin()->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->login($merchant)
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Email atau password salah.');
    }

    public function test_super_admin_login_is_not_affected_by_store_expiry(): void
    {
        $admin = $this->createSuperAdmin();

        $this->login($admin)
            ->assertOk()
            ->assertJsonPath('data.role', 'super_admin');
    }

    public function test_tenant_context_rejects_expired_store(): void
    {
        $activeStore = $this->createStore(expiresAt: now()->addDay());
        $activeMerchant = $this->createMerchant($activeStore);

        $context = new TenantContext;
        $context->setMerchantStore($activeMerchant, $activeStore);
        $this->assertSame($activeStore->id, $context->storeId());

        $expiredStore = $this->createStore(expiresAt: now()->subMinute());
        $expiredMerchant = $this->createMerchant($expiredStore);

        $this->expectException(AuthorizationException::class);
        (new TenantContext)->setMerchantStore($expiredMerchant, $expiredStore);
    }

    public function test_expiry_boundary_is_inclusive_and_one_second_later_is_valid(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-07 10:00:00', 'Asia/Jakarta'));

        $boundary = $this->createStore(expiresAt: now());
        $oneSecondLater = $this->createStore(expiresAt: now()->addSecond());

        $this->assertTrue($boundary->isExpired());
        $this->assertFalse($oneSecondLater->isExpired());

        $this->assertSame([$boundary->id], Store::query()->expired()->pluck('id')->all());
        $this->assertSame([$oneSecondLater->id], Store::query()->notExpired()->pluck('id')->all());
    }

    public function test_expiry_does_not_change_is_active(): void
    {
        $store = $this->createStore(expiresAt: now()->subDay(), active: true);

        $fresh = $store->fresh();

        $this->assertNotNull($fresh);
        $this->assertTrue($fresh->is_active);
        $this->assertTrue($fresh->isExpired());
        $this->assertFalse($fresh->isOperational());
    }

    public function test_extending_expiry_restores_merchant_access(): void
    {
        $store = $this->createStore(expiresAt: now()->addDay());
        $merchant = $this->createMerchant($store);

        $this->login($merchant)->assertOk();
        $this->withSpaOrigin()->getJson('/api/v1/testing/merchant/context')->assertOk();

        $store->expires_at = now()->subMinute();
        $store->save();
        $this->withSpaOrigin()->getJson('/api/v1/testing/merchant/context')->assertForbidden();

        $store->expires_at = now()->addYear();
        $store->save();
        $this->withSpaOrigin()->getJson('/api/v1/testing/merchant/context')->assertOk();
    }

    public function test_store_scopes_split_expired_expiring_and_valid_stores(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-07 10:00:00', 'Asia/Jakarta'));

        $expired = $this->createStore(expiresAt: now()->subSecond());
        $expiring = $this->createStore(expiresAt: now()->addDays(3));
        $valid = $this->createStore(expiresAt: now()->addDays(30));
        $sentinel = $this->createStore(expiresAt: CarbonImmutable::parse('2099-12-31 23:59:59', 'Asia/Jakarta'));

        $this->assertSame([$expired->id], Store::query()->expired()->pluck('id')->all());
        $this->assertSame([$expiring->id], Store::query()->expiringWithin(7)->pluck('id')->all());
        $this->assertSame(
            [$expiring->id, $valid->id, $sentinel->id],
            Store::query()->notExpired()->orderBy('id')->pluck('id')->all()
        );
    }

    public function test_is_operational_requires_active_unexpired_untrashed_store(): void
    {
        $operational = $this->createStore(expiresAt: now()->addDay(), active: true);
        $inactive = $this->createStore(expiresAt: now()->addDay(), active: false);
        $expired = $this->createStore(expiresAt: now()->subMinute(), active: true);
        $trashed = $this->createStore(expiresAt: now()->addDay(), active: true);
        $trashed->delete();

        $this->assertTrue($operational->isOperational());
        $this->assertFalse($inactive->isOperational());
        $this->assertFalse($expired->isOperational());
        $this->assertFalse($trashed->isOperational());
    }

    private function createSchema(): void
    {
        Schema::create('stores', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 150)->unique();
            $table->boolean('is_active')->default(true);
            $table->dateTime('expires_at');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->string('role');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('session_generation')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('name', 150);
            $table->decimal('price', 12, 2)->default(0);
            $table->boolean('is_available')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function registerTestRoutes(): void
    {
        Route::middleware('api')->prefix('api/v1/testing')->group(function (): void {
            Route::get('/merchant/context', fn () => response()->json([
                'data' => ['store_id' => app(TenantContext::class)->storeId()],
            ]))->middleware([
                'auth:sanctum', 'session.auth', 'active.user', 'role:merchant', 'merchant.store', 'tenant.context',
            ]);
        });
    }

    private function login(User $user): TestResponse
    {
        return $this->withSpaOrigin()->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);
    }

    private function withSpaOrigin(): static
    {
        return $this->withHeaders([
            'Origin' => self::SPA_ORIGIN,
            'Referer' => self::SPA_ORIGIN.'/',
        ]);
    }

    private function createStore(
        DateTimeInterface|string $expiresAt,
        bool $active = true,
    ): Store {
        return Store::create([
            'name' => 'Store '.bin2hex(random_bytes(4)),
            'slug' => 'store-'.bin2hex(random_bytes(6)),
            'is_active' => $active,
            'expires_at' => $expiresAt,
        ]);
    }

    private function createMerchant(Store $store): User
    {
        $user = new User([
            'name' => 'Merchant',
            'email' => 'merchant-'.bin2hex(random_bytes(6)).'@example.test',
            'password' => 'correct-password',
        ]);
        $user->role = 'merchant';
        $user->store_id = $store->getKey();
        $user->save();

        return $user;
    }

    private function createSuperAdmin(): User
    {
        $user = new User([
            'name' => 'Super Admin',
            'email' => 'admin-'.bin2hex(random_bytes(6)).'@example.test',
            'password' => 'correct-password',
        ]);
        $user->role = 'super_admin';
        $user->store_id = null;
        $user->save();

        return $user;
    }
}
