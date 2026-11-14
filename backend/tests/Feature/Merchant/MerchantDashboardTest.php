<?php

namespace Tests\Feature\Merchant;

use App\Models\Store;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithPrefixedMySqlDatabase;
use Tests\TestCase;

class MerchantDashboardTest extends TestCase
{
    use InteractsWithPrefixedMySqlDatabase;

    private const TABLES = ['audit_logs', 'orders', 'queues', 'queue_sessions', 'users', 'stores'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=', 'session.driver' => 'array']);
        $this->setUpPrefixedMySqlDatabase('merchant_dashboard');
        foreach (self::TABLES as $table) Schema::dropIfExists($table);
        Schema::create('stores', function (Blueprint $table): void {
            $table->id(); $table->string('name', 150); $table->string('slug', 150)->unique(); $table->text('description')->nullable(); $table->string('phone', 30)->nullable(); $table->text('address')->nullable(); $table->string('google_maps_url', 255)->nullable(); $table->unsignedInteger('avg_service_time')->default(10); $table->string('queue_prefix', 5)->default('A'); $table->enum('ordering_mode', ['queue_only', 'queue_and_order']); $table->boolean('is_active')->default(true); $table->dateTime('expires_at'); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('email')->unique(); $table->string('password'); $table->string('role'); $table->unsignedBigInteger('store_id')->nullable(); $table->unsignedBigInteger('session_generation')->default(1); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('queue_sessions', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('store_id'); $table->string('session_code'); $table->date('session_date'); $table->unsignedInteger('last_queue_number')->default(0); $table->string('status'); $table->timestamp('opened_at')->nullable(); $table->timestamp('closed_at')->nullable(); $table->timestamps();
        });
        Schema::create('queues', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('store_id'); $table->unsignedBigInteger('queue_session_id'); $table->unsignedInteger('queue_number'); $table->string('formatted_number'); $table->string('status'); $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->id(); $table->string('order_code'); $table->unsignedBigInteger('store_id'); $table->unsignedBigInteger('queue_id')->nullable(); $table->string('status'); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('store_id')->nullable(); $table->unsignedBigInteger('user_id')->nullable(); $table->string('action'); $table->string('entity_type')->nullable(); $table->unsignedBigInteger('entity_id')->nullable(); $table->json('old_values')->nullable(); $table->json('new_values')->nullable(); $table->ipAddress('ip_address')->nullable(); $table->text('user_agent')->nullable(); $table->timestamp('created_at')->useCurrent();
        });
    }

    protected function tearDown(): void
    {
        $this->tearDownPrefixedMySqlDatabase(self::TABLES);
        parent::tearDown();
    }

    public function test_merchant_dashboard_aggregates_only_own_active_session(): void
    {
        [$store, $merchant] = $this->merchantFixture('own');
        $other = $this->storeFixture('other');
        DB::table('queue_sessions')->insert([
            ['store_id' => $store->id, 'session_code' => 'OWN', 'session_date' => now()->toDateString(), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['store_id' => $other->id, 'session_code' => 'OTHER', 'session_date' => now()->toDateString(), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $session = DB::table('queue_sessions')->where('store_id', $store->id)->value('id');
        DB::table('queues')->insert([['store_id' => $store->id, 'queue_session_id' => $session, 'queue_number' => 1, 'formatted_number' => 'A001', 'status' => 'waiting', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('orders')->insert([['order_code' => 'ORD-1', 'store_id' => $store->id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]]);

        $response = $this->withSpaOrigin()->withSession(['auth_session_generation' => 1])->actingAs($merchant, 'web')->getJson('/api/v1/merchant/dashboard');
        $response->assertOk()->assertJsonPath('data.store.slug', $store->slug)->assertJsonPath('data.queue_session.session_code', 'OWN')->assertJsonPath('data.queue_counts.waiting', 1)->assertJsonPath('data.order_counts.pending', 1);
        $response->assertJsonMissing(['session_code' => 'OTHER'])->assertJsonMissingPath('data.store.id');
    }

    public function test_merchant_can_update_allowed_profile_fields_and_audit_is_written(): void
    {
        [$store, $merchant] = $this->merchantFixture('profile');
        $response = $this->withSpaOrigin()->withSession(['auth_session_generation' => 1])->actingAs($merchant, 'web')->patchJson('/api/v1/merchant/profile', ['name' => 'Nama Baru', 'phone' => '0812']);
        $response->assertOk()->assertJsonPath('data.name', 'Nama Baru')->assertJsonPath('data.phone', '0812')->assertJsonMissingPath('data.store_id');
        $this->assertSame('Nama Baru', $store->fresh()->name);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogService::STORE_UPDATED, 'store_id' => $store->id]);
        $this->withSpaOrigin()->withSession(['auth_session_generation' => 1])->actingAs($merchant, 'web')->patchJson('/api/v1/merchant/profile', ['is_active' => false])->assertUnprocessable();
        $this->assertTrue((bool) $store->fresh()->is_active);
    }

    /** @return array{0: Store, 1: User} */
    private function merchantFixture(string $suffix): array
    {
        $store = $this->storeFixture($suffix);
        $id = DB::table('users')->insertGetId(['name' => 'Merchant', 'email' => $suffix.'-'.bin2hex(random_bytes(4)).'@example.test', 'password' => 'irrelevant', 'role' => 'merchant', 'store_id' => $store->id, 'session_generation' => 1, 'created_at' => now(), 'updated_at' => now()]);
        return [$store, User::query()->findOrFail($id)];
    }

    private function storeFixture(string $suffix): Store
    {
        return Store::create(['name' => 'Store '.$suffix, 'slug' => 'store-'.$suffix.'-'.bin2hex(random_bytes(3)), 'ordering_mode' => 'queue_and_order', 'is_active' => true, 'expires_at' => now()->addYear()]);
    }

    private function withSpaOrigin(): static
    {
        return $this->withHeaders([
            'Origin' => 'http://localhost:3000',
            'Referer' => 'http://localhost:3000/',
        ]);
    }
}
