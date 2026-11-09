<?php

namespace Tests\Feature\Admin;

use App\Models\Store;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Store\StoreManagementService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StoreManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());

        config(['session.driver' => 'array']);
        config(['sanctum.stateful' => ['localhost:3000']]);

        foreach (['sessions', 'personal_access_tokens', 'audit_logs', 'users', 'stores'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('stores', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 150)->unique();
            $table->text('description')->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->string('google_maps_url', 255)->nullable();
            $table->unsignedInteger('avg_service_time')->default(10);
            $table->string('queue_prefix', 5)->default('A');
            $table->string('ordering_mode')->default('queue_and_order');
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

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 100);
            $table->string('entity_type', 100)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_create_generates_slug_from_name_and_writes_audit_log(): void
    {
        $admin = $this->createSuperAdmin();

        $store = $this->service()->create([
            'name' => 'Warung Nusantara',
            'description' => 'Makanan rumahan',
            'expires_at' => now()->addYear(),
        ], $admin, '203.0.113.10', 'Test Agent/1.0');

        $this->assertSame('warung-nusantara', $store->slug);
        $this->assertSame('Makanan rumahan', $store->description);
        $this->assertTrue($store->is_active);

        $log = DB::table('audit_logs')->where('id', 1)->first();
        $this->assertNotNull($log);
        $this->assertSame(AuditLogService::STORE_CREATED, $log->action);
        $this->assertSame(Store::class, $log->entity_type);
        $this->assertSame($store->id, (int) $log->entity_id);
        $this->assertNull($log->old_values);
        $snapshot = json_decode($log->new_values, true);
        $this->assertSame($store->slug, $snapshot['slug']);
        $this->assertTrue($snapshot['is_active']);
        $this->assertSame(10, $snapshot['avg_service_time']);
        $this->assertSame('A', $snapshot['queue_prefix']);
        $this->assertSame('queue_and_order', $snapshot['ordering_mode']);
        $this->assertSame('203.0.113.10', $log->ip_address);
        $this->assertSame('Test Agent/1.0', $log->user_agent);
    }

    public function test_create_uses_deterministic_suffixes_and_counts_soft_deleted_slugs(): void
    {
        $admin = $this->createSuperAdmin();

        $first = $this->service()->create([
            'name' => 'Warung Nusantara',
            'expires_at' => now()->addYear(),
        ], $admin);
        $first->delete();

        $second = $this->service()->create([
            'name' => 'Warung Nusantara',
            'expires_at' => now()->addYear(),
        ], $admin);

        $third = $this->service()->create([
            'name' => 'Warung Nusantara',
            'expires_at' => now()->addYear(),
        ], $admin);

        $this->assertSame('warung-nusantara-2', $second->slug);
        $this->assertSame('warung-nusantara-3', $third->slug);
    }

    public function test_explicit_slug_conflict_is_rejected_without_partial_writes(): void
    {
        $admin = $this->createSuperAdmin();
        $this->createStore(['slug' => 'warung-nusantara']);

        try {
            $this->service()->create([
                'name' => 'Warung Nusantara',
                'slug' => 'warung-nusantara',
                'expires_at' => now()->addYear(),
            ], $admin);
            $this->fail('Slug conflict must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('slug', $exception->errors());
        }

        $this->assertSame(1, DB::table('stores')->count());
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_explicit_slug_is_normalized_before_insert(): void
    {
        $admin = $this->createSuperAdmin();

        $store = $this->service()->create([
            'name' => 'Warung Nusantara',
            'slug' => '  My Store  ',
            'expires_at' => now()->addYear(),
        ], $admin);

        $this->assertSame('my-store', $store->slug);
    }

    public function test_update_only_changes_profile_fields_and_audits_old_and_new_values(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createStore([
            'name' => 'Nama Lama',
            'slug' => 'nama-lama',
            'description' => 'Deskripsi lama',
            'is_active' => true,
            'expires_at' => now()->addYear(),
        ]);
        $originalExpiry = $store->fresh()->expires_at;

        $updated = $this->service()->update($store, [
            'name' => 'Nama Baru',
            'description' => 'Deskripsi baru',
            'is_active' => false,
            'expires_at' => now()->subYear(),
            'slug' => 'slug-baru',
            'store_id' => 999,
        ], $admin);

        $this->assertSame('Nama Baru', $updated->name);
        $this->assertSame('Deskripsi baru', $updated->description);
        $this->assertSame('nama-lama', $updated->slug);
        $this->assertTrue($updated->is_active);
        $this->assertTrue($updated->expires_at->equalTo($originalExpiry));

        $log = DB::table('audit_logs')->where('action', AuditLogService::STORE_UPDATED)->first();
        $this->assertNotNull($log);
        $this->assertSame('Nama Lama', json_decode($log->old_values, true)['name']);
        $this->assertSame('Nama Baru', json_decode($log->new_values, true)['name']);
    }

    public function test_update_without_changes_does_not_write_an_audit_log(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createStore(['name' => 'Nama Sama']);

        $this->service()->update($store, ['name' => 'Nama Sama'], $admin);

        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_create_rolls_back_when_audit_write_fails(): void
    {
        $admin = $this->createSuperAdmin();
        Schema::drop('audit_logs');

        try {
            $this->service()->create([
                'name' => 'Warung Gagal',
                'expires_at' => now()->addYear(),
            ], $admin);
            $this->fail('Audit failure must abort the transaction.');
        } catch (QueryException) {
            $this->assertSame(0, DB::table('stores')->count());
        }
    }

    public function test_admin_can_create_list_show_and_update_store_via_api(): void
    {
        $admin = $this->createSuperAdmin();
        $this->login($admin)->assertOk();

        $create = $this->withSpaOrigin()->postJson('/api/v1/admin/stores', [
            'name' => 'Warung API',
            'description' => 'Deskripsi',
            'expires_at' => now()->addYear()->toIso8601String(),
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.name', 'Warung API')
            ->assertJsonPath('data.slug', 'warung-api')
            ->assertJsonPath('data.avg_service_time', 10)
            ->assertJsonPath('data.queue_prefix', 'A')
            ->assertJsonPath('data.ordering_mode', 'queue_and_order')
            ->assertJsonPath('data.is_active', true);

        $storeId = $create->json('data.id');

        $this->withSpaOrigin()->getJson('/api/v1/admin/stores?search=Warung')
            ->assertOk()
            ->assertJsonPath('data.0.id', $storeId);

        $this->withSpaOrigin()->getJson("/api/v1/admin/stores/{$storeId}")
            ->assertOk()
            ->assertJsonPath('data.id', $storeId);

        $this->withSpaOrigin()->patchJson("/api/v1/admin/stores/{$storeId}", [
            'name' => 'Warung API Baru',
        ])->assertOk()->assertJsonPath('data.name', 'Warung API Baru');

        $this->assertSame(2, DB::table('audit_logs')->count());
    }

    public function test_create_endpoint_rejects_null_for_defaulted_optional_fields(): void
    {
        $admin = $this->createSuperAdmin();
        $this->login($admin)->assertOk();

        foreach (['avg_service_time', 'queue_prefix', 'ordering_mode', 'is_active'] as $field) {
            $this->withSpaOrigin()->postJson('/api/v1/admin/stores', [
                'name' => 'Warung Null',
                'expires_at' => now()->addYear()->toIso8601String(),
                $field => null,
            ])->assertUnprocessable()->assertJsonValidationErrors([$field]);
        }

        $this->assertSame(0, DB::table('stores')->count());
    }

    public function test_create_endpoint_accepts_explicit_values_for_defaulted_optional_fields(): void
    {
        $admin = $this->createSuperAdmin();
        $this->login($admin)->assertOk();

        $this->withSpaOrigin()->postJson('/api/v1/admin/stores', [
            'name' => 'Warung Custom',
            'avg_service_time' => 25,
            'queue_prefix' => 'B',
            'ordering_mode' => 'queue_only',
            'is_active' => false,
            'expires_at' => now()->addYear()->toIso8601String(),
        ])->assertCreated()
            ->assertJsonPath('data.avg_service_time', 25)
            ->assertJsonPath('data.queue_prefix', 'B')
            ->assertJsonPath('data.ordering_mode', 'queue_only')
            ->assertJsonPath('data.is_active', false);
    }

    public function test_create_endpoint_rejects_store_id_and_slug_conflict(): void
    {
        $admin = $this->createSuperAdmin();
        $this->login($admin)->assertOk();
        $this->createStore(['slug' => 'warung-nusantara']);

        $this->withSpaOrigin()->postJson('/api/v1/admin/stores', [
            'name' => 'Warung Nusantara',
            'slug' => 'warung-nusantara',
            'expires_at' => now()->addYear()->toIso8601String(),
            'store_id' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors(['store_id', 'slug']);

        $this->assertSame(1, DB::table('stores')->count());
    }

    public function test_update_endpoint_rejects_lifecycle_and_slug_fields(): void
    {
        $admin = $this->createSuperAdmin();
        $this->login($admin)->assertOk();
        $store = $this->createStore();

        foreach ([
            'is_active' => false,
            'expires_at' => now()->addYear()->toIso8601String(),
            'slug' => 'slug-baru',
            'store_id' => 1,
        ] as $field => $value) {
            $this->withSpaOrigin()->patchJson("/api/v1/admin/stores/{$store->id}", [
                $field => $value,
            ])->assertUnprocessable()->assertJsonValidationErrors([$field]);
        }

        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_index_filters_by_search_active_and_expiry_status(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-07 10:00:00', 'Asia/Jakarta'));

        $expired = $this->createStore([
            'name' => 'Expired Store',
            'slug' => 'expired-store',
            'is_active' => true,
            'expires_at' => now()->subMinute(),
        ]);
        $expiring = $this->createStore([
            'name' => 'Expiring Store',
            'slug' => 'expiring-store',
            'is_active' => true,
            'expires_at' => now()->addDays(3),
        ]);
        $valid = $this->createStore([
            'name' => 'Valid Store',
            'slug' => 'valid-store',
            'is_active' => false,
            'expires_at' => now()->addDays(30),
        ]);
        $sentinel = $this->createStore([
            'name' => 'Sentinel Store',
            'slug' => 'sentinel-store',
            'is_active' => true,
            'expires_at' => CarbonImmutable::parse('2099-12-31 23:59:59', 'Asia/Jakarta'),
        ]);
        $deleted = $this->createStore([
            'name' => 'Deleted Store',
            'slug' => 'deleted-store',
            'is_active' => true,
            'expires_at' => now()->subMinute(),
        ]);
        $deleted->delete();

        $admin = $this->createSuperAdmin();
        $this->login($admin)->assertOk();

        $this->withSpaOrigin()->getJson('/api/v1/admin/stores?expiry_status=expired')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $expired->id);

        $this->withSpaOrigin()->getJson('/api/v1/admin/stores?expiry_status=expiring&days=7')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $expiring->id);

        $this->withSpaOrigin()->getJson('/api/v1/admin/stores?expiry_status=valid')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->withSpaOrigin()->getJson('/api/v1/admin/stores?is_active=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $valid->id);

        $this->withSpaOrigin()->getJson('/api/v1/admin/stores?search=Expired')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $expired->id);
    }

    public function test_expiry_alerts_returns_separate_groups_and_excludes_sentinel(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-07 10:00:00', 'Asia/Jakarta'));

        $expired = $this->createStore(['expires_at' => now()->subMinute()]);
        $expiring = $this->createStore(['expires_at' => now()->addDays(3)]);
        $this->createStore(['expires_at' => now()->addDays(30)]);
        $this->createStore([
            'expires_at' => CarbonImmutable::parse('2099-12-31 23:59:59', 'Asia/Jakarta'),
        ]);
        $deleted = $this->createStore(['expires_at' => now()->subMinute()]);
        $deleted->delete();

        $admin = $this->createSuperAdmin();
        $this->login($admin)->assertOk();

        $response = $this->withSpaOrigin()
            ->getJson('/api/v1/admin/stores/expiry-alerts?days=7')
            ->assertOk()
            ->assertJsonPath('meta.days', 7);

        $this->assertSame([$expired->id], array_column($response->json('data.expired.data'), 'id'));
        $this->assertSame([$expiring->id], array_column($response->json('data.expiring.data'), 'id'));
    }

    public function test_expiry_alerts_paginates_each_group_with_separate_page_parameters(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-07 10:00:00', 'Asia/Jakarta'));

        $expiredOldest = $this->createStore(['expires_at' => now()->subMinutes(3)]);
        $expiredMiddle = $this->createStore(['expires_at' => now()->subMinutes(2)]);
        $expiredNewest = $this->createStore(['expires_at' => now()->subMinute()]);

        $expiringSoonest = $this->createStore(['expires_at' => now()->addDay()]);
        $expiringMiddle = $this->createStore(['expires_at' => now()->addDays(2)]);
        $expiringLatest = $this->createStore(['expires_at' => now()->addDays(3)]);

        $admin = $this->createSuperAdmin();
        $this->login($admin)->assertOk();

        $firstPage = $this->withSpaOrigin()
            ->getJson('/api/v1/admin/stores/expiry-alerts?days=7&per_page=2&expired_page=1&expiring_page=1')
            ->assertOk()
            ->assertJsonPath('data.expired.meta.current_page', 1)
            ->assertJsonPath('data.expired.meta.total', 3)
            ->assertJsonPath('data.expiring.meta.current_page', 1)
            ->assertJsonPath('data.expiring.meta.total', 3);

        $secondPage = $this->withSpaOrigin()
            ->getJson('/api/v1/admin/stores/expiry-alerts?days=7&per_page=2&expired_page=2&expiring_page=2')
            ->assertOk()
            ->assertJsonPath('data.expired.meta.current_page', 2)
            ->assertJsonPath('data.expiring.meta.current_page', 2);

        $expiredIds = [
            ...array_column($firstPage->json('data.expired.data'), 'id'),
            ...array_column($secondPage->json('data.expired.data'), 'id'),
        ];
        $expiringIds = [
            ...array_column($firstPage->json('data.expiring.data'), 'id'),
            ...array_column($secondPage->json('data.expiring.data'), 'id'),
        ];

        $this->assertSame([$expiredOldest->id, $expiredMiddle->id, $expiredNewest->id], $expiredIds);
        $this->assertSame([$expiringSoonest->id, $expiringMiddle->id, $expiringLatest->id], $expiringIds);
        $this->assertCount(3, array_unique($expiredIds));
        $this->assertCount(3, array_unique($expiringIds));

        $this->withSpaOrigin()
            ->getJson('/api/v1/admin/stores/expiry-alerts?expired_page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['expired_page']);

        $this->withSpaOrigin()
            ->getJson('/api/v1/admin/stores/expiry-alerts?expiring_page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['expiring_page']);
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

    private function service(): StoreManagementService
    {
        return app(StoreManagementService::class);
    }

    private function createStore(array $attributes = []): Store
    {
        return Store::create(array_merge([
            'name' => 'Store '.bin2hex(random_bytes(4)),
            'slug' => 'store-'.bin2hex(random_bytes(6)),
            'is_active' => true,
            'expires_at' => now()->addYear(),
        ], $attributes));
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
