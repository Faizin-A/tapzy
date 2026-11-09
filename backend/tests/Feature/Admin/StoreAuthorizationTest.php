<?php

namespace Tests\Feature\Admin;

use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StoreAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());

        config(['session.driver' => 'array']);
        config(['sanctum.stateful' => ['localhost:3000']]);

        Schema::dropIfExists('sessions');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('stores');

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

        app(TenantContext::class)->clear();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    public function test_super_admin_with_platform_access_can_manage_stores(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createStore();

        app(TenantContext::class)->enablePlatformAccess($admin);

        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', Store::class));
        $this->assertTrue(Gate::forUser($admin)->allows('create', Store::class));
        $this->assertTrue(Gate::forUser($admin)->allows('view', $store));
        $this->assertTrue(Gate::forUser($admin)->allows('update', $store));
        $this->assertTrue(Gate::forUser($admin)->allows('manage', $store));
        $this->assertTrue(Gate::forUser($admin)->allows('delete', $store));
        $this->assertFalse(Gate::forUser($admin)->allows('restore', $store));
        $this->assertFalse(Gate::forUser($admin)->allows('forceDelete', $store));
    }

    public function test_super_admin_with_platform_access_cannot_manage_trashed_store(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createStore();
        app(TenantContext::class)->enablePlatformAccess($admin);
        $store->delete();

        $this->assertFalse(Gate::forUser($admin)->allows('manage', $store));
        $this->assertFalse(Gate::forUser($admin)->allows('delete', $store));
    }

    public function test_super_admin_without_platform_access_cannot_manage_stores(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createStore();

        $this->assertFalse(Gate::forUser($admin)->allows('viewAny', Store::class));
        $this->assertFalse(Gate::forUser($admin)->allows('create', Store::class));
        $this->assertFalse(Gate::forUser($admin)->allows('view', $store));
        $this->assertFalse(Gate::forUser($admin)->allows('manage', $store));
        $this->assertFalse(Gate::forUser($admin)->allows('delete', $store));
    }

    public function test_merchant_can_only_view_and_update_own_store(): void
    {
        $store = $this->createStore();
        $otherStore = $this->createStore();
        $merchant = $this->createMerchant($store);

        app(TenantContext::class)->setMerchantStore($merchant, $store);

        $this->assertTrue(Gate::forUser($merchant)->allows('view', $store));
        $this->assertTrue(Gate::forUser($merchant)->allows('update', $store));
        $this->assertFalse(Gate::forUser($merchant)->allows('view', $otherStore));
        $this->assertFalse(Gate::forUser($merchant)->allows('update', $otherStore));
        $this->assertFalse(Gate::forUser($merchant)->allows('viewAny', Store::class));
        $this->assertFalse(Gate::forUser($merchant)->allows('create', Store::class));
        $this->assertFalse(Gate::forUser($merchant)->allows('manage', $store));
        $this->assertFalse(Gate::forUser($merchant)->allows('delete', $store));
    }

    public function test_merchant_without_tenant_context_is_denied(): void
    {
        $store = $this->createStore();
        $merchant = $this->createMerchant($store);

        $this->assertFalse(Gate::forUser($merchant)->allows('view', $store));
        $this->assertFalse(Gate::forUser($merchant)->allows('update', $store));
    }

    public function test_merchant_with_expired_store_is_denied(): void
    {
        $store = $this->createStore(expiresAt: now()->addDay());
        $merchant = $this->createMerchant($store);

        app(TenantContext::class)->setMerchantStore($merchant, $store);
        $this->assertTrue(Gate::forUser($merchant)->allows('view', $store));

        $store->expires_at = now()->subMinute();
        $store->save();

        $this->assertFalse(Gate::forUser($merchant)->allows('view', $store));
        $this->assertFalse(Gate::forUser($merchant)->allows('update', $store));
    }

    public function test_merchant_cannot_access_admin_store_endpoints(): void
    {
        $store = $this->createStore();
        $merchant = $this->createMerchant($store);
        $this->login($merchant)->assertOk();

        $this->withSpaOrigin()->getJson('/api/v1/admin/stores')->assertForbidden();
        $this->withSpaOrigin()->getJson("/api/v1/admin/stores/{$store->id}")->assertForbidden();
        $this->withSpaOrigin()->patchJson("/api/v1/admin/stores/{$store->id}", [
            'name' => 'Tidak Boleh',
        ])->assertForbidden();
        $this->withSpaOrigin()->deleteJson("/api/v1/admin/stores/{$store->id}")->assertForbidden();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->withSpaOrigin()->getJson('/api/v1/admin/stores')->assertUnauthorized();
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
            'Origin' => 'http://localhost:3000',
            'Referer' => 'http://localhost:3000/',
        ]);
    }

    private function createStore(?\DateTimeInterface $expiresAt = null): Store
    {
        return Store::create([
            'name' => 'Store '.bin2hex(random_bytes(4)),
            'slug' => 'store-'.bin2hex(random_bytes(6)),
            'is_active' => true,
            'expires_at' => $expiresAt ?? now()->addYear(),
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
