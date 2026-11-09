<?php

namespace Tests\Feature\Admin;

use App\Models\Store;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Store\StoreManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StoreLifecycleTest extends TestCase
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

    public function test_set_active_toggles_status_and_writes_audit_logs_idempotently(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createStore(['is_active' => true]);

        $deactivated = $this->service()->setActive($store, false, $admin);
        $this->assertFalse($deactivated->is_active);

        $activated = $this->service()->setActive($deactivated, true, $admin);
        $this->assertTrue($activated->is_active);

        $this->service()->setActive($activated, true, $admin);

        $this->assertSame(2, DB::table('audit_logs')->count());
        $this->assertSame(
            [AuditLogService::STORE_DEACTIVATED, AuditLogService::STORE_ACTIVATED],
            DB::table('audit_logs')->orderBy('id')->pluck('action')->all()
        );

        $firstLog = DB::table('audit_logs')->where('id', 1)->first();
        $this->assertTrue(json_decode($firstLog->old_values, true)['is_active']);
        $this->assertFalse(json_decode($firstLog->new_values, true)['is_active']);
    }

    public function test_set_expiry_updates_utc_value_and_audits_without_changing_active_status(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createStore(['is_active' => true]);
        $expiry = CarbonImmutable::parse('2027-01-01 00:00:00', 'Asia/Jakarta');

        $updated = $this->service()->setExpiry($store, $expiry, $admin);

        $this->assertTrue($updated->expires_at->equalTo($expiry));
        $this->assertTrue($updated->is_active);
        $this->assertSame(
            '2026-12-31 17:00:00',
            DB::table('stores')->where('id', $store->id)->value('expires_at')
        );

        $log = DB::table('audit_logs')->where('action', AuditLogService::STORE_EXPIRY_UPDATED)->first();
        $this->assertNotNull($log);
        $this->assertNotSame(
            json_decode($log->old_values, true)['expires_at'],
            json_decode($log->new_values, true)['expires_at']
        );
    }

    public function test_set_expiry_same_instant_does_not_write_audit_or_touch_timestamp(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createStore();
        $expiry = CarbonImmutable::parse('2027-01-01 00:00:00', 'Asia/Jakarta');

        $updated = $this->service()->setExpiry($store, $expiry, $admin);
        $this->assertSame(1, DB::table('audit_logs')->count());

        DB::table('stores')->where('id', $store->id)->update(['updated_at' => '2000-01-01 00:00:00']);

        $sameInstant = CarbonImmutable::parse('2026-12-31 17:00:00', 'UTC');
        $result = $this->service()->setExpiry($updated, $sameInstant, $admin);

        $this->assertSame(1, DB::table('audit_logs')->count());
        $this->assertSame(
            '2000-01-01 00:00:00',
            DB::table('stores')->where('id', $store->id)->value('updated_at')
        );
        $this->assertTrue($result->expires_at->equalTo($expiry));
    }

    public function test_set_expiry_rejects_past_dates_without_writing_audit_log(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createStore();
        $original = DB::table('stores')->where('id', $store->id)->value('expires_at');

        try {
            $this->service()->setExpiry($store, now()->subMinute(), $admin);
            $this->fail('Past expiry must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('expires_at', $exception->errors());
        }

        $this->assertSame($original, DB::table('stores')->where('id', $store->id)->value('expires_at'));
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_set_expiry_accepts_sentinel_date(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createStore();
        $sentinel = CarbonImmutable::parse('2099-12-31 23:59:59', 'Asia/Jakarta');

        $updated = $this->service()->setExpiry($store, $sentinel, $admin);

        $this->assertTrue($updated->expires_at->equalTo($sentinel));
        $this->assertFalse($updated->isExpired());
    }

    public function test_delete_soft_deletes_store_and_writes_audit_log(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createStore(['name' => 'Toko Dihapus']);

        $this->service()->delete($store, $admin);

        $this->assertNull(Store::find($store->id));
        $trashed = Store::withTrashed()->find($store->id);
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame(1, DB::table('stores')->count());

        $log = DB::table('audit_logs')->where('action', AuditLogService::STORE_DELETED)->first();
        $this->assertNotNull($log);
        $this->assertSame('Toko Dihapus', json_decode($log->old_values, true)['name']);
        $this->assertNotNull(json_decode($log->new_values, true)['deleted_at']);
    }

    public function test_delete_rolls_back_when_audit_write_fails(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createStore();
        Schema::drop('audit_logs');

        try {
            $this->service()->delete($store, $admin);
            $this->fail('Audit failure must abort the delete transaction.');
        } catch (QueryException) {
            $this->assertNotNull(Store::find($store->id));
            $this->assertNull(Store::withTrashed()->find($store->id)->deleted_at);
        }
    }

    public function test_status_endpoint_updates_status_and_audits(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createStore(['is_active' => true]);
        $this->login($admin)->assertOk();

        $this->withSpaOrigin()
            ->patchJson("/api/v1/admin/stores/{$store->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($store->fresh()->is_active);
        $this->assertSame(
            AuditLogService::STORE_DEACTIVATED,
            DB::table('audit_logs')->value('action')
        );
    }

    public function test_expiry_endpoint_updates_expiry_and_rejects_past_dates(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createStore(['is_active' => true]);
        $this->login($admin)->assertOk();
        $future = CarbonImmutable::parse('2027-01-01 00:00:00', 'Asia/Jakarta');

        $this->withSpaOrigin()
            ->patchJson("/api/v1/admin/stores/{$store->id}/expiry", [
                'expires_at' => $future->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertTrue($store->fresh()->expires_at->equalTo($future));
        $this->assertSame(1, DB::table('audit_logs')->count());

        $this->withSpaOrigin()
            ->patchJson("/api/v1/admin/stores/{$store->id}/expiry", [
                'expires_at' => now()->subMinute()->toIso8601String(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['expires_at']);

        $this->assertSame(1, DB::table('audit_logs')->count());
    }

    public function test_delete_endpoint_soft_deletes_and_returns_no_content(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createStore();
        $this->login($admin)->assertOk();

        $this->withSpaOrigin()
            ->deleteJson("/api/v1/admin/stores/{$store->id}")
            ->assertNoContent();

        $this->assertNull(Store::find($store->id));
        $this->assertNotNull(Store::withTrashed()->find($store->id)->deleted_at);
        $this->assertSame(1, DB::table('stores')->count());
        $this->assertSame(AuditLogService::STORE_DELETED, DB::table('audit_logs')->value('action'));

        $this->withSpaOrigin()
            ->getJson("/api/v1/admin/stores/{$store->id}")
            ->assertNotFound();
    }

    public function test_soft_deleted_store_is_hidden_from_list_and_cannot_be_updated(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createStore();
        $store->delete();
        $this->login($admin)->assertOk();

        $this->withSpaOrigin()
            ->getJson('/api/v1/admin/stores')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withSpaOrigin()
            ->patchJson("/api/v1/admin/stores/{$store->id}", ['name' => 'Tidak Boleh'])
            ->assertNotFound();
    }

    public function test_status_and_expiry_endpoints_reject_store_id(): void
    {
        $admin = $this->createSuperAdmin();
        $store = $this->createStore();
        $this->login($admin)->assertOk();

        $this->withSpaOrigin()
            ->patchJson("/api/v1/admin/stores/{$store->id}/status", [
                'is_active' => false,
                'store_id' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['store_id']);

        $this->withSpaOrigin()
            ->patchJson("/api/v1/admin/stores/{$store->id}/expiry", [
                'expires_at' => now()->addYear()->toIso8601String(),
                'store_id' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['store_id']);
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
