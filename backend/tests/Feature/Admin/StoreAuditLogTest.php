<?php

namespace Tests\Feature\Admin;

use App\Models\Store;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StoreAuditLogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());

        foreach (['audit_logs', 'users', 'stores'] as $table) {
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
    }

    public function test_record_persists_store_actor_entity_and_request_metadata(): void
    {
        $store = $this->createStore();
        $admin = $this->createSuperAdmin();

        $log = app(AuditLogService::class)->record(
            AuditLogService::STORE_CREATED,
            $store,
            $admin,
            [],
            ['name' => 'Warung Baru'],
            '203.0.113.10',
            'Test Agent/1.0',
        );

        $row = DB::table('audit_logs')->where('id', $log->id)->first();

        $this->assertNotNull($row);
        $this->assertSame($store->id, (int) $row->store_id);
        $this->assertSame($admin->id, (int) $row->user_id);
        $this->assertSame(AuditLogService::STORE_CREATED, $row->action);
        $this->assertSame(Store::class, $row->entity_type);
        $this->assertSame($store->id, (int) $row->entity_id);
        $this->assertNull($row->old_values);
        $this->assertSame(['name' => 'Warung Baru'], json_decode($row->new_values, true));
        $this->assertSame('203.0.113.10', $row->ip_address);
        $this->assertSame('Test Agent/1.0', $row->user_agent);
        $this->assertNotNull($row->created_at);
    }

    public function test_snapshot_is_whitelisted_and_expiry_is_utc_iso(): void
    {
        $store = $this->createStore();
        $store->expires_at = CarbonImmutable::parse('2099-12-31 23:59:59', 'Asia/Jakarta');
        $store->save();

        $snapshot = app(AuditLogService::class)->storeSnapshot($store->fresh());

        $this->assertSame([
            'name',
            'slug',
            'description',
            'phone',
            'address',
            'google_maps_url',
            'avg_service_time',
            'queue_prefix',
            'ordering_mode',
            'is_active',
            'expires_at',
        ], array_keys($snapshot));
        $this->assertSame('2099-12-31T16:59:59+00:00', $snapshot['expires_at']);
    }

    public function test_sensitive_keys_are_recursively_redacted(): void
    {
        $store = $this->createStore();
        $admin = $this->createSuperAdmin();

        $log = app(AuditLogService::class)->record(
            AuditLogService::STORE_UPDATED,
            $store,
            $admin,
            ['name' => 'Lama', 'Password' => 'secret'],
            [
                'name' => 'Baru',
                'credentials' => [
                    'password' => 'secret',
                    'nested' => ['access_token' => 'token-value'],
                ],
                'safe' => 'visible',
            ],
        );

        $row = DB::table('audit_logs')->where('id', $log->id)->first();
        $old = json_decode($row->old_values, true);
        $new = json_decode($row->new_values, true);

        $this->assertSame('Lama', $old['name']);
        $this->assertSame('[REDACTED]', $old['Password']);
        $this->assertSame('Baru', $new['name']);
        $this->assertSame('[REDACTED]', $new['credentials']['password']);
        $this->assertSame('[REDACTED]', $new['credentials']['nested']['access_token']);
        $this->assertSame('visible', $new['safe']);
    }

    public function test_audit_log_model_remains_append_only(): void
    {
        $store = $this->createStore();
        $admin = $this->createSuperAdmin();

        $log = app(AuditLogService::class)->record(
            AuditLogService::STORE_CREATED,
            $store,
            $admin,
            [],
            ['name' => $store->name],
        );

        $this->expectException(\LogicException::class);
        $log->action = 'changed';
        $log->save();
    }

    private function createStore(): Store
    {
        return Store::create([
            'name' => 'Store '.bin2hex(random_bytes(4)),
            'slug' => 'store-'.bin2hex(random_bytes(6)),
            'is_active' => true,
            'expires_at' => now()->addYear(),
        ]);
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
