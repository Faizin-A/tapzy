<?php

namespace Tests\Feature;

use App\Http\Requests\TenantOwnedResourceRequest;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuthenticationAndTenantTest extends TestCase
{
    private const SPA_ORIGIN = 'http://localhost:3000';

    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());

        config(['session.driver' => 'array']);
        config(['sanctum.stateful' => ['localhost:3000']]);

        Schema::dropIfExists('products');
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
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->string('role');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('session_generation')->default(1);
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

        $this->registerTestRoutes();
    }

    public function test_csrf_cookie_endpoint_initializes_first_party_csrf_cookie(): void
    {
        $this->withSpaOrigin()
            ->get('/sanctum/csrf-cookie')
            ->assertNoContent()
            ->assertCookie('XSRF-TOKEN');
    }

    public function test_stateful_api_rejects_mutation_without_csrf_token(): void
    {
        config(['sanctum.middleware.validate_csrf_token' => EnforcedTestCsrfToken::class]);

        $this->withSpaOrigin()->get('/sanctum/csrf-cookie')->assertNoContent();

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/login', [
                'email' => 'csrf@example.test',
                'password' => 'incorrect-password',
            ])
            ->assertStatus(419);
    }

    public function test_database_session_cookie_logout_and_old_cookie_rejection(): void
    {
        $user = $this->createSuperAdmin('database-logout@example.test');
        [$loginResponse, $sessionCookie, $csrfCookie, $sessionId] = $this->loginWithBrowserCookies($user);

        $loginResponse->assertOk();
        $this->assertDatabaseHas('sessions', [
            'id' => $sessionId,
            'user_id' => $user->id,
        ]);

        $this->resetAuthAndSessionDrivers();

        $this->withSpaOrigin()
            ->withUnencryptedCookie(config('session.cookie'), $sessionCookie)
            ->withUnencryptedCookie('XSRF-TOKEN', $csrfCookie)
            ->withHeader('X-XSRF-TOKEN', $csrfCookie)
            ->withCredentials()
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
        $this->resetAuthAndSessionDrivers();

        $this->withSpaOrigin()
            ->withUnencryptedCookie(config('session.cookie'), $sessionCookie)
            ->withUnencryptedCookie('XSRF-TOKEN', $csrfCookie)
            ->withCredentials()
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_valid_csrf_login_persists_generation_and_round_trips_session_cookie(): void
    {
        $user = $this->createSuperAdmin('cookie-round-trip@example.test');
        $currentUser = User::query()->findOrFail($user->id);
        $this->assertSame(1, $currentUser->session_generation);
        [$loginResponse, $sessionCookie, $csrfCookie, $sessionId] = $this->loginWithBrowserCookies($user);

        $loginResponse->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonMissingPath('data.session_generation');

        $sessionRow = DB::table('sessions')->where('id', $sessionId)->first();
        $this->assertNotNull($sessionRow);
        $this->assertSame($user->id, (int) $sessionRow->user_id);
        $payload = json_decode(base64_decode($sessionRow->payload), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('auth_session_generation', $payload, 'Session payload keys: '.implode(', ', array_keys($payload)));
        $this->assertSame($currentUser->session_generation, $payload['auth_session_generation']);

        $this->resetAuthAndSessionDrivers();

        $this->withSpaOrigin()
            ->withUnencryptedCookie(config('session.cookie'), $sessionCookie)
            ->withUnencryptedCookie('XSRF-TOKEN', $csrfCookie)
            ->withCredentials()
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_super_admin_can_login_using_email_and_receives_safe_profile(): void
    {
        $user = $this->createSuperAdmin('admin@example.test');

        $response = $this->login($user->email);

        $response->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.role', 'super_admin')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token')
            ->assertJsonMissingPath('data.store_id');

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_merchant_can_login_and_get_store_summary(): void
    {
        $store = $this->createStore();
        $user = $this->createMerchant($store, 'merchant@example.test');

        $this->login($user->email)
            ->assertOk()
            ->assertJsonPath('data.role', 'merchant')
            ->assertJsonPath('data.store.id', $store->id)
            ->assertJsonPath('data.store.slug', $store->slug);
    }

    public function test_unknown_email_and_wrong_password_have_the_same_generic_response(): void
    {
        $this->createSuperAdmin('known@example.test');

        $wrongPassword = $this->login('known@example.test', 'incorrect-password');
        $unknownEmail = $this->login('unknown@example.test', 'incorrect-password');

        $wrongPassword->assertUnprocessable();
        $unknownEmail->assertUnprocessable();
        $this->assertSame($wrongPassword->getContent(), $unknownEmail->getContent());
        $this->assertSame('Email atau password salah.', $wrongPassword->json('errors.email.0'));
    }

    public function test_soft_deleted_super_admin_and_merchant_cannot_login(): void
    {
        $admin = $this->createSuperAdmin('deleted-admin@example.test');
        $admin->delete();

        $store = $this->createStore();
        $merchant = $this->createMerchant($store, 'deleted-merchant@example.test');
        $merchant->delete();

        $this->login($admin->email)->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Email atau password salah.');
        $this->login($merchant->email)->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Email atau password salah.');
    }

    public function test_soft_deleted_user_cannot_continue_an_existing_session(): void
    {
        $user = $this->createSuperAdmin('session-deleted@example.test');
        $this->login($user->email)->assertOk();

        $user->delete();

        $this->withSpaOrigin()->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_soft_deleted_merchant_cannot_continue_an_existing_session(): void
    {
        $store = $this->createStore();
        $user = $this->createMerchant($store, 'session-deleted-merchant@example.test');
        $this->login($user->email)->assertOk();

        $user->delete();

        $this->withSpaOrigin()->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_inactive_or_soft_deleted_store_denies_merchant_login(): void
    {
        $inactiveStore = $this->createStore(false);
        $inactiveMerchant = $this->createMerchant($inactiveStore, 'inactive@example.test');

        $deletedStore = $this->createStore();
        $deletedMerchant = $this->createMerchant($deletedStore, 'deleted-store@example.test');
        $deletedStore->delete();

        $this->login($inactiveMerchant->email)->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Email atau password salah.');
        $this->login($deletedMerchant->email)->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Email atau password salah.');
    }

    public function test_merchant_store_status_is_rechecked_on_each_protected_request(): void
    {
        $store = $this->createStore();
        $user = $this->createMerchant($store, 'status-check@example.test');
        $this->createProduct($store, 'A product');
        $this->login($user->email)->assertOk();

        $store->is_active = false;
        $store->save();

        $this->withSpaOrigin()->getJson('/api/v1/testing/merchant/products')->assertForbidden();

        $store->is_active = true;
        $store->save();
        $store->delete();

        $this->withSpaOrigin()->getJson('/api/v1/testing/merchant/products')->assertForbidden();
    }

    public function test_logout_invalidates_session_and_protected_request_requires_authentication(): void
    {
        $user = $this->createSuperAdmin('logout@example.test');

        $this->withSpaOrigin()->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->login($user->email)->assertOk();
        $this->assertAuthenticatedAs($user, 'web');

        $this->withSpaOrigin()->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertGuest('web');
        $this->withSpaOrigin()->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_personal_access_token_cannot_authenticate_dashboard_routes(): void
    {
        $user = $this->createSuperAdmin('pat-rejected@example.test');
        $plainTextToken = $user->createToken('test-only')->plainTextToken;

        foreach ([
            ['GET', '/api/v1/auth/me'],
            ['GET', '/api/user'],
            ['POST', '/api/v1/auth/logout'],
            ['GET', '/api/v1/testing/merchant/products'],
            ['GET', '/api/v1/testing/admin/products'],
        ] as [$method, $uri]) {
            $this->resetAuthAndSessionDrivers();

            $this->withSpaOrigin()
                ->withHeader('Authorization', 'Bearer '.$plainTextToken)
                ->json($method, $uri)
                ->assertUnauthorized();
        }
    }

    public function test_soft_delete_and_restore_revoke_super_admin_sessions_and_isolate_other_users(): void
    {
        $this->assertSessionRevocationAndRestore(
            $this->createSuperAdmin('session-generation-admin@example.test'),
            $this->createSuperAdmin('session-generation-other-admin@example.test'),
        );
    }

    public function test_soft_delete_and_restore_revoke_merchant_sessions_and_isolate_other_users(): void
    {
        $targetStore = $this->createStore();
        $otherStore = $this->createStore();

        $this->assertSessionRevocationAndRestore(
            $this->createMerchant($targetStore, 'session-generation-merchant@example.test'),
            $this->createMerchant($otherStore, 'session-generation-other-merchant@example.test'),
        );
    }

    public function test_missing_null_invalid_and_stale_generation_markers_are_rejected(): void
    {
        $user = $this->createSuperAdmin('generation-validation@example.test');

        foreach (['missing', null, 'not-a-generation', 0] as $invalidMarker) {
            [$loginResponse, $sessionCookie, $csrfCookie, $sessionId] = $this->loginWithBrowserCookies($user);
            $loginResponse->assertOk();

            $sessionRow = DB::table('sessions')->where('id', $sessionId)->first();
            $payload = json_decode(base64_decode($sessionRow->payload), true, flags: JSON_THROW_ON_ERROR);

            if ($invalidMarker === 'missing') {
                unset($payload['auth_session_generation']);
            } else {
                $payload['auth_session_generation'] = $invalidMarker;
            }

            DB::table('sessions')->where('id', $sessionId)->update([
                'payload' => base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
            ]);

            $this->resetAuthAndSessionDrivers();

            $response = $this->withSpaOrigin()
                ->withUnencryptedCookie(config('session.cookie'), $sessionCookie)
                ->withUnencryptedCookie('XSRF-TOKEN', $csrfCookie)
                ->withCredentials()
                ->getJson('/api/v1/auth/me');
            $this->assertSame(401, $response->status(), 'Rejected marker: '.var_export($invalidMarker, true));
        }
    }

    public function test_soft_deleted_super_admin_sessions_are_revoked_and_do_not_return_after_restore(): void
    {
        $user = $this->createSuperAdmin('generation-admin@example.test');
        $otherUser = $this->createSuperAdmin('other-admin@example.test');

        $this->assertSessionRevocationAndRestore($user, $otherUser);
    }

    public function test_soft_deleted_merchant_sessions_are_revoked_and_do_not_return_after_restore(): void
    {
        $targetStore = $this->createStore();
        $otherStore = $this->createStore();
        $user = $this->createMerchant($targetStore, 'generation-merchant@example.test');
        $otherUser = $this->createMerchant($otherStore, 'other-merchant@example.test');

        $this->assertSessionRevocationAndRestore($user, $otherUser);
    }

    public function test_role_middleware_separates_merchant_and_super_admin_routes(): void
    {
        $merchantStore = $this->createStore();
        $merchant = $this->createMerchant($merchantStore, 'role-merchant@example.test');
        $this->login($merchant->email)->assertOk();

        $this->withSpaOrigin()->getJson('/api/v1/testing/admin/products')->assertForbidden();

        $admin = $this->createSuperAdmin('role-admin@example.test');
        $this->withSpaOrigin()->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->login($admin->email)->assertOk()->assertJsonPath('data.role', 'super_admin');
        $this->withSpaOrigin()->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.role', 'super_admin');
        $this->withSpaOrigin()->getJson('/api/v1/testing/merchant/products')->assertForbidden();
    }

    public function test_merchant_queries_and_resource_binding_are_store_scoped(): void
    {
        $firstStore = $this->createStore();
        $merchant = $this->createMerchant($firstStore, 'tenant-a@example.test');
        $firstProduct = $this->createProduct($firstStore, 'Tenant A product');

        $secondStore = $this->createStore();
        $secondProduct = $this->createProduct($secondStore, 'Tenant B product');

        $this->login($merchant->email)->assertOk();

        $this->withSpaOrigin()
            ->getJson('/api/v1/testing/merchant/products?store_id='.$secondStore->id)
            ->assertOk()
            ->assertJsonPath('data.tenant_id', $firstStore->id)
            ->assertJsonPath('data.product_ids.0', $firstProduct->id)
            ->assertJsonMissing(['id' => $secondProduct->id]);

        $this->withSpaOrigin()
            ->withHeader('X-Store-Id', (string) $secondStore->id)
            ->getJson('/api/v1/testing/merchant/products/'.$firstProduct->id)
            ->assertOk()
            ->assertJsonPath('data.id', $firstProduct->id);

        $this->withSpaOrigin()
            ->getJson('/api/v1/testing/merchant/products/'.$secondProduct->id)
            ->assertNotFound();

        $context = app(TenantContext::class);
        $authenticatedUser = User::query()->findOrFail($merchant->id);
        $context->setMerchantStore($authenticatedUser, $firstStore);
        $this->assertFalse(Gate::forUser($authenticatedUser)->allows('view', $secondProduct));
        $context->clear();

        $this->assertNull(app(TenantContext::class)->storeId());
    }

    public function test_form_request_rejects_client_store_id_and_foreign_tenant_relations(): void
    {
        $store = $this->createStore();
        $merchant = $this->createMerchant($store, 'request-tenant@example.test');
        $this->createProduct($store, 'Own product');

        $otherStore = $this->createStore();
        $foreignProduct = $this->createProduct($otherStore, 'Foreign product');

        $this->login($merchant->email)->assertOk();

        $this->withSpaOrigin()
            ->postJson('/api/v1/testing/merchant/validate', ['store_id' => $otherStore->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('store_id');

        $this->withSpaOrigin()
            ->postJson('/api/v1/testing/merchant/validate', ['related_product_id' => $foreignProduct->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('related_product_id');
    }

    public function test_super_admin_cross_tenant_access_requires_explicit_platform_route(): void
    {
        $this->createProduct($this->createStore(), 'Store One');
        $this->createProduct($this->createStore(), 'Store Two');
        $admin = $this->createSuperAdmin('platform-admin@example.test');
        $this->login($admin->email)->assertOk();

        $this->withSpaOrigin()
            ->getJson('/api/v1/testing/admin/products')
            ->assertOk()
            ->assertJsonCount(2, 'data.product_ids');

        $this->assertFalse(app(TenantContext::class)->allowsPlatformAccess());
    }

    public function test_login_rate_limit_returns_http_429(): void
    {
        $this->createSuperAdmin('limited@example.test');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->login(' LIMITED@example.test ', 'wrong-password')->assertUnprocessable();
        }

        $this->login('limited@example.test', 'wrong-password')->assertTooManyRequests();
    }

    private function loginWithBrowserCookies(User $user): array
    {
        config([
            'session.driver' => 'database',
            'session.connection' => DB::getDefaultConnection(),
            'session.table' => 'sessions',
            'sanctum.middleware.validate_csrf_token' => EnforcedTestCsrfToken::class,
        ]);
        $this->resetAuthAndSessionDrivers();

        $csrfResponse = $this->withSpaOrigin()->get('/sanctum/csrf-cookie')->assertNoContent();
        $sessionCookieName = (string) config('session.cookie');
        $csrfCookie = $csrfResponse->getCookie('XSRF-TOKEN', false)?->getValue();
        $preLoginSessionCookie = $csrfResponse->getCookie($sessionCookieName, false)?->getValue();

        $this->assertNotNull($csrfCookie);
        $this->assertNotNull($preLoginSessionCookie);

        $loginResponse = $this->withSpaOrigin()
            ->withUnencryptedCookie($sessionCookieName, $preLoginSessionCookie)
            ->withUnencryptedCookie('XSRF-TOKEN', $csrfCookie)
            ->withHeader('X-XSRF-TOKEN', $csrfCookie)
            ->withCredentials()
            ->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'correct-password',
            ]);

        $sessionCookie = $loginResponse->getCookie($sessionCookieName, false)?->getValue();
        $sessionId = $loginResponse->getCookie($sessionCookieName)?->getValue();
        $csrfCookie = $loginResponse->getCookie('XSRF-TOKEN', false)?->getValue() ?? $csrfCookie;

        $this->assertNotNull($sessionCookie);
        $this->assertNotNull($sessionId);

        return [$loginResponse, $sessionCookie, $csrfCookie, $sessionId];
    }

    private function resetAuthAndSessionDrivers(): void
    {
        Auth::forgetGuards();
        app('session')->forgetDrivers();
    }

    private function assertSessionRevocationAndRestore(User $user, User $otherUser): void
    {
        [$loginResponse, $oldSessionCookie, $oldCsrfCookie, $sessionId] = $this->loginWithBrowserCookies($user);
        $loginResponse->assertOk();

        $initialGeneration = $user->fresh()->session_generation;
        $oldSession = DB::table('sessions')->where('id', $sessionId)->first();
        $this->assertNotNull($oldSession);
        $this->assertSame($user->id, (int) $oldSession->user_id);

        $additionalUserSessionId = 'additional-'.bin2hex(random_bytes(12));
        $otherUserSessionId = 'other-'.bin2hex(random_bytes(12));
        $this->insertTestSession($additionalUserSessionId, (int) $user->id);
        $this->insertTestSession($otherUserSessionId, (int) $otherUser->id);

        $this->assertTrue($user->delete());
        $deletedUser = User::withTrashed()->findOrFail($user->id);
        $this->assertNotNull($deletedUser->deleted_at);
        $this->assertSame($initialGeneration + 1, $deletedUser->session_generation);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['id' => $otherUserSessionId, 'user_id' => $otherUser->id]);

        // Simulate a request that writes its old session after the soft-delete purge.
        DB::table('sessions')->insert((array) $oldSession);

        $this->assertTrue($deletedUser->restore());
        $restoredUser = User::query()->findOrFail($user->id);
        $this->assertNull($restoredUser->deleted_at);
        $this->assertSame($initialGeneration + 2, $restoredUser->session_generation);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);

        // A late stale row still cannot revive the prior cookie after restoration.
        DB::table('sessions')->insert((array) $oldSession);
        $this->resetAuthAndSessionDrivers();

        $this->withSpaOrigin()
            ->withUnencryptedCookie((string) config('session.cookie'), $oldSessionCookie)
            ->withUnencryptedCookie('XSRF-TOKEN', $oldCsrfCookie)
            ->withCredentials()
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();

        $this->assertDatabaseHas('sessions', ['id' => $otherUserSessionId, 'user_id' => $otherUser->id]);

        [$freshLogin, $freshSessionCookie, $freshCsrfCookie] = $this->loginWithBrowserCookies($restoredUser);
        $freshLogin->assertOk();
        $this->resetAuthAndSessionDrivers();

        $this->withSpaOrigin()
            ->withUnencryptedCookie((string) config('session.cookie'), $freshSessionCookie)
            ->withUnencryptedCookie('XSRF-TOKEN', $freshCsrfCookie)
            ->withCredentials()
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    private function insertTestSession(string $id, int $userId): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'session-generation-test',
            'payload' => base64_encode('{}'),
            'last_activity' => time(),
        ]);
    }

    private function registerTestRoutes(): void
    {
        Route::middleware('api')->prefix('api/v1/testing')->group(function (): void {
            Route::get('/merchant/products', function () {
                return response()->json([
                    'data' => [
                        'tenant_id' => app(TenantContext::class)->storeId(),
                        'product_ids' => Product::query()->orderBy('id')->pluck('id')->all(),
                    ],
                ]);
            })->middleware(['auth:sanctum', 'session.auth', 'active.user', 'role:merchant', 'merchant.store', 'tenant.context']);

            Route::get('/merchant/products/{product}', function (Product $product) {
                return response()->json(['data' => ['id' => $product->id]]);
            })->middleware([
                'auth:sanctum', 'session.auth', 'active.user', 'role:merchant', 'merchant.store', 'tenant.context', 'can:view,product',
            ]);

            Route::post('/merchant/validate', function (TestTenantProductRequest $request) {
                return response()->json([
                    'data' => ['tenant_id' => app(TenantContext::class)->storeId()],
                ]);
            })->middleware(['auth:sanctum', 'session.auth', 'active.user', 'role:merchant', 'merchant.store', 'tenant.context']);

            Route::get('/admin/products', function () {
                return response()->json([
                    'data' => ['product_ids' => Product::query()->orderBy('id')->pluck('id')->all()],
                ]);
            })->middleware([
                'auth:sanctum', 'session.auth', 'active.user', 'role:super_admin', 'tenant.platform', 'can:viewAny,'.Product::class,
            ]);
        });
    }

    private function withSpaOrigin(): static
    {
        return $this->withHeaders([
            'Origin' => self::SPA_ORIGIN,
            'Referer' => self::SPA_ORIGIN.'/',
        ]);
    }

    private function login(string $email, string $password = 'correct-password')
    {
        return $this->withSpaOrigin()->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);
    }

    private function createStore(bool $active = true): Store
    {
        return Store::create([
            'name' => 'Test store',
            'slug' => 'store-'.bin2hex(random_bytes(6)),
            'is_active' => $active,
            'expires_at' => now()->addYear(),
        ]);
    }

    private function createSuperAdmin(string $email): User
    {
        $user = new User([
            'name' => 'Super admin',
            'email' => $email,
            'password' => 'correct-password',
        ]);
        $user->role = 'super_admin';
        $user->store_id = null;
        $user->save();

        return $user;
    }

    private function createMerchant(Store $store, string $email): User
    {
        $user = new User([
            'name' => 'Merchant',
            'email' => $email,
            'password' => 'correct-password',
        ]);
        $user->role = 'merchant';
        $user->store_id = $store->getKey();
        $user->save();

        return $user;
    }

    private function createProduct(Store $store, string $name): Product
    {
        return $store->products()->create([
            'name' => $name,
            'price' => '125.00',
        ]);
    }
}

class TestTenantProductRequest extends TenantOwnedResourceRequest
{
    protected function resourceModelClass(): string
    {
        return Product::class;
    }

    protected function resourceRules(): array
    {
        return [
            'related_product_id' => ['required', 'integer', $this->existsInTenant('products')],
        ];
    }
}

class EnforcedTestCsrfToken extends ValidateCsrfToken
{
    protected function runningUnitTests()
    {
        return false;
    }
}
