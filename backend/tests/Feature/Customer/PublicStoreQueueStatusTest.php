<?php

namespace Tests\Feature\Customer;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithPrefixedMySqlDatabase;
use Tests\TestCase;
use Throwable;

class PublicStoreQueueStatusTest extends TestCase
{
    use InteractsWithPrefixedMySqlDatabase;

    private const ISOLATED_TABLES = [
        'queues',
        'queue_sessions',
        'nfc_cards',
        'stores',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPrefixedMySqlDatabase('cust_qstatus');

        try {
            foreach (self::ISOLATED_TABLES as $table) {
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

            Schema::create('nfc_cards', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('store_id')->constrained()->restrictOnDelete();
                $table->string('card_uid', 100)->unique();
                $table->char('public_token_hash', 64)->unique();
                $table->string('label', 100);
                $table->string('type')->default('table');
                $table->boolean('is_active')->default(false);
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('activated_at')->nullable();
                $table->timestamp('deactivated_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unsignedBigInteger('active_store_id')
                    ->nullable()
                    ->virtualAs('CASE WHEN deleted_at IS NULL THEN store_id ELSE NULL END');
                $table->unique('active_store_id');
            });

            Schema::create('queue_sessions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('store_id')->constrained()->restrictOnDelete()->cascadeOnUpdate();
                $table->string('session_code', 50);
                $table->date('session_date')->index();
                $table->unsignedInteger('last_queue_number')->default(0);
                $table->enum('status', ['active', 'closed'])->default('active')->index();
                $table->unsignedBigInteger('active_store_id')
                    ->nullable()
                    ->virtualAs("IF(`status` = 'active', `store_id`, NULL)");
                $table->timestamp('opened_at')->useCurrent();
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();
                $table->index('store_id');
                $table->unique('active_store_id', 'uq_store_single_active_session');
                $table->unique(['store_id', 'session_code'], 'uq_store_session_code');
            });

            Schema::create('queues', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('store_id')->constrained()->restrictOnDelete()->cascadeOnUpdate();
                $table->foreignId('queue_session_id')->constrained('queue_sessions')->restrictOnDelete()->cascadeOnUpdate();
                $table->foreignId('nfc_card_id')->nullable()->constrained('nfc_cards')->nullOnDelete()->cascadeOnUpdate();
                $table->unsignedInteger('queue_number');
                $table->string('formatted_number', 20);
                $table->string('customer_name', 100)->nullable();
                $table->string('customer_phone', 30)->nullable();
                $table->enum('source', ['nfc', 'barcode', 'manual'])->default('nfc');
                $table->char('access_token_hash', 64);
                $table->enum('status', ['waiting', 'called', 'in_service', 'skipped', 'completed', 'cancelled'])->default('waiting');
                $table->timestamp('called_at')->nullable();
                $table->timestamp('served_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index('store_id');
                $table->index('queue_session_id');
                $table->index('nfc_card_id');
                $table->index('status');
                $table->unique(['queue_session_id', 'queue_number'], 'uq_session_queue_num');
                $table->unique('access_token_hash', 'uq_queue_access_token_hash');
                $table->index(['store_id', 'status', 'created_at'], 'idx_store_queue_status_time');
            });
        } catch (Throwable $exception) {
            $this->tearDownPrefixedMySqlDatabase(self::ISOLATED_TABLES);
            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        $this->tearDownPrefixedMySqlDatabase(self::ISOLATED_TABLES);
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Happy Path Tests
    // -------------------------------------------------------------------------

    public function test_returns_active_status_with_in_service_priority_over_called(): void
    {
        $store = $this->createStore(avgServiceTime: 10);
        $token = $this->token();
        $this->insertCard($store->id, $token);
        $session = $this->createActiveSession($store->id, lastQueueNumber: 7);

        // 3 waiting queues
        for ($i = 1; $i <= 3; $i++) {
            $this->insertQueue($store->id, $session->id, $i, 'waiting');
        }

        // 1 called queue (should be overridden by in_service)
        $this->insertQueue($store->id, $session->id, 4, 'called', calledAt: now()->subMinutes(5));

        // 2 in_service queues — most recent served_at wins
        $this->insertQueue($store->id, $session->id, 5, 'in_service', servedAt: now()->subMinutes(10));
        $this->insertQueue($store->id, $session->id, 6, 'in_service', servedAt: now()->subMinutes(2));  // winner

        // 1 skipped — must not appear in currently_serving
        $this->insertQueue($store->id, $session->id, 7, 'skipped');

        $response = $this->getJson("/api/v1/public/stores/{$token}/queue-status");

        $response->assertOk()
            ->assertJsonPath('data.is_session_active', true)
            ->assertJsonPath('data.currently_serving_number', 6)   // most recent in_service
            ->assertJsonPath('data.waiting_count', 3)              // only 'waiting' counted
            ->assertJsonPath('data.estimated_wait_minutes', 30)    // 3 × 10
            ->assertJsonPath('data.avg_service_time_minutes', 10)
            ->assertJsonPath('data.last_queue_number', 7);
    }

    public function test_returns_called_queue_when_no_in_service_exists(): void
    {
        $store = $this->createStore(avgServiceTime: 5);
        $token = $this->token();
        $this->insertCard($store->id, $token);
        $session = $this->createActiveSession($store->id, lastQueueNumber: 4);

        // 2 called queues with different called_at — most recent (id=2) wins
        $this->insertQueue($store->id, $session->id, 1, 'called', calledAt: now()->subMinutes(10));
        $this->insertQueue($store->id, $session->id, 2, 'called', calledAt: now()->subMinutes(2));  // winner

        $this->insertQueue($store->id, $session->id, 3, 'waiting');
        $this->insertQueue($store->id, $session->id, 4, 'waiting');

        $response = $this->getJson("/api/v1/public/stores/{$token}/queue-status");

        $response->assertOk()
            ->assertJsonPath('data.is_session_active', true)
            ->assertJsonPath('data.currently_serving_number', 2)
            ->assertJsonPath('data.waiting_count', 2)
            ->assertJsonPath('data.estimated_wait_minutes', 10);  // 2 × 5
    }

    public function test_currently_serving_is_null_when_only_waiting_or_skipped_queues_exist(): void
    {
        $store = $this->createStore();
        $token = $this->token();
        $this->insertCard($store->id, $token);
        $session = $this->createActiveSession($store->id, lastQueueNumber: 3);

        $this->insertQueue($store->id, $session->id, 1, 'waiting');
        $this->insertQueue($store->id, $session->id, 2, 'waiting');
        $this->insertQueue($store->id, $session->id, 3, 'skipped');

        $response = $this->getJson("/api/v1/public/stores/{$token}/queue-status");

        $response->assertOk()
            ->assertJsonPath('data.currently_serving', null)
            ->assertJsonPath('data.currently_serving_number', null)
            ->assertJsonPath('data.waiting_count', 2);
    }

    public function test_returns_safe_inactive_state_when_no_active_session_exists(): void
    {
        $store = $this->createStore(avgServiceTime: 15);
        $token = $this->token();
        $this->insertCard($store->id, $token);

        // Only a closed session — no active one
        DB::table('queue_sessions')->insert([
            'store_id' => $store->id,
            'session_code' => 'QS-CLOSED',
            'session_date' => now()->toDateString(),
            'status' => 'closed',
            'closed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/public/stores/{$token}/queue-status");

        $response->assertOk()
            ->assertJsonPath('data.is_session_active', false)
            ->assertJsonPath('data.session_code', null)
            ->assertJsonPath('data.currently_serving', null)
            ->assertJsonPath('data.currently_serving_number', null)
            ->assertJsonPath('data.waiting_count', 0)
            ->assertJsonPath('data.estimated_wait_minutes', 0)
            ->assertJsonPath('data.avg_service_time_minutes', 15)
            ->assertJsonPath('data.last_queue_number', 0);
    }

    public function test_returns_safe_inactive_state_when_store_has_no_session_at_all(): void
    {
        $store = $this->createStore();
        $token = $this->token();
        $this->insertCard($store->id, $token);

        $response = $this->getJson("/api/v1/public/stores/{$token}/queue-status");

        $response->assertOk()
            ->assertJsonPath('data.is_session_active', false)
            ->assertJsonPath('data.waiting_count', 0)
            ->assertJsonPath('data.currently_serving', null);
    }

    public function test_waiting_count_excludes_non_waiting_statuses(): void
    {
        $store = $this->createStore(avgServiceTime: 8);
        $token = $this->token();
        $this->insertCard($store->id, $token);
        $session = $this->createActiveSession($store->id, lastQueueNumber: 7);

        $this->insertQueue($store->id, $session->id, 1, 'waiting');
        $this->insertQueue($store->id, $session->id, 2, 'waiting');
        $this->insertQueue($store->id, $session->id, 3, 'called');
        $this->insertQueue($store->id, $session->id, 4, 'in_service');
        $this->insertQueue($store->id, $session->id, 5, 'skipped');
        $this->insertQueue($store->id, $session->id, 6, 'completed');
        $this->insertQueue($store->id, $session->id, 7, 'cancelled');

        $response = $this->getJson("/api/v1/public/stores/{$token}/queue-status");

        $response->assertOk()
            ->assertJsonPath('data.waiting_count', 2)             // only 'waiting'
            ->assertJsonPath('data.estimated_wait_minutes', 16);  // 2 × 8
    }

    // -------------------------------------------------------------------------
    // Security / Negative Tests
    // -------------------------------------------------------------------------

    public function test_returns_404_on_invalid_token_format(): void
    {
        $this->getJson('/api/v1/public/stores/too-short/queue-status')->assertNotFound();
        $this->getJson('/api/v1/public/stores/'.str_repeat('!', 40).'/queue-status')->assertNotFound();
        $this->getJson('/api/v1/public/stores/'.str_repeat('A', 39).'/queue-status')->assertNotFound();
        $this->getJson('/api/v1/public/stores/'.str_repeat('A', 41).'/queue-status')->assertNotFound();
    }

    public function test_returns_404_on_nonexistent_token(): void
    {
        $this->getJson('/api/v1/public/stores/'.str_repeat('Z', 40).'/queue-status')->assertNotFound();
    }

    public function test_returns_404_when_card_is_inactive(): void
    {
        $store = $this->createStore();
        $token = $this->token();
        $this->insertCard($store->id, $token, active: false);

        $this->getJson("/api/v1/public/stores/{$token}/queue-status")->assertNotFound();
    }

    public function test_returns_404_when_card_is_soft_deleted(): void
    {
        $store = $this->createStore();
        $token = $this->token();
        $this->insertCard($store->id, $token, deleted: true);

        $this->getJson("/api/v1/public/stores/{$token}/queue-status")->assertNotFound();
    }

    public function test_returns_404_when_store_is_inactive(): void
    {
        $store = $this->createStore(isActive: false);
        $token = $this->token();
        $this->insertCard($store->id, $token);

        $this->getJson("/api/v1/public/stores/{$token}/queue-status")->assertNotFound();
    }

    public function test_returns_404_when_store_is_expired(): void
    {
        $store = $this->createStore(expiresAt: now()->subHour());
        $token = $this->token();
        $this->insertCard($store->id, $token);

        $this->getJson("/api/v1/public/stores/{$token}/queue-status")->assertNotFound();
    }

    public function test_returns_404_when_store_is_soft_deleted(): void
    {
        $store = $this->createStore();
        $token = $this->token();
        $this->insertCard($store->id, $token);

        DB::table('stores')->where('id', $store->id)->update(['deleted_at' => now()]);

        $this->getJson("/api/v1/public/stores/{$token}/queue-status")->assertNotFound();
    }

    public function test_does_not_update_nfc_card_last_used_at_on_polling(): void
    {
        $store = $this->createStore();
        $token = $this->token();
        $cardId = $this->insertCard($store->id, $token);

        // Simulate multiple polls
        $this->getJson("/api/v1/public/stores/{$token}/queue-status")->assertOk();
        $this->getJson("/api/v1/public/stores/{$token}/queue-status")->assertOk();
        $this->getJson("/api/v1/public/stores/{$token}/queue-status")->assertOk();

        $lastUsedAt = DB::table('nfc_cards')->where('id', $cardId)->value('last_used_at');
        $this->assertNull($lastUsedAt, 'last_used_at must not be updated by queue-status polling');
    }

    public function test_response_does_not_expose_internal_sensitive_fields(): void
    {
        $store = $this->createStore();
        $token = $this->token();
        $this->insertCard($store->id, $token);
        $this->createActiveSession($store->id);

        $response = $this->getJson("/api/v1/public/stores/{$token}/queue-status");

        $response->assertOk();
        $response->assertJsonMissingPath('data.store_id');
        $response->assertJsonMissingPath('data.id');
        $response->assertJsonMissingPath('data.card_uid');
        $response->assertJsonMissingPath('data.public_token_hash');
        $response->assertJsonMissingPath('data.access_token_hash');
    }

    public function test_rate_limiting_enforced_per_token_and_ip(): void
    {
        $store = $this->createStore();
        $token = $this->token();
        $this->insertCard($store->id, $token);

        // Hit the per-IP-token limit (60/min)
        for ($i = 0; $i < 60; $i++) {
            $this->getJson("/api/v1/public/stores/{$token}/queue-status")->assertOk();
        }

        $this->getJson("/api/v1/public/stores/{$token}/queue-status")->assertStatus(429);
    }

    // -------------------------------------------------------------------------
    // Helper Methods
    // -------------------------------------------------------------------------

    private function createStore(
        int $avgServiceTime = 10,
        bool $isActive = true,
        mixed $expiresAt = null,
    ): object {
        $id = DB::table('stores')->insertGetId([
            'name' => 'Store '.bin2hex(random_bytes(4)),
            'slug' => 'store-'.bin2hex(random_bytes(6)),
            'is_active' => $isActive,
            'avg_service_time' => $avgServiceTime,
            'queue_prefix' => 'A',
            'ordering_mode' => 'queue_and_order',
            'expires_at' => $expiresAt ? $expiresAt->utc()->format('Y-m-d H:i:s') : now()->addYear()->utc()->format('Y-m-d H:i:s'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (object) DB::table('stores')->where('id', $id)->first();
    }

    private function insertCard(
        int $storeId,
        string $token,
        bool $active = true,
        bool $deleted = false,
    ): int {
        return DB::table('nfc_cards')->insertGetId([
            'store_id' => $storeId,
            'card_uid' => 'hw-'.bin2hex(random_bytes(6)),
            'public_token_hash' => hash('sha256', $token),
            'label' => 'Meja 01',
            'type' => 'table',
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $deleted ? now() : null,
        ]);
    }

    private function createActiveSession(int $storeId, int $lastQueueNumber = 0): object
    {
        $id = DB::table('queue_sessions')->insertGetId([
            'store_id' => $storeId,
            'session_code' => 'QS-'.bin2hex(random_bytes(4)),
            'session_date' => now()->toDateString(),
            'last_queue_number' => $lastQueueNumber,
            'status' => 'active',
            'opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (object) DB::table('queue_sessions')->where('id', $id)->first();
    }

    private function insertQueue(
        int $storeId,
        int $sessionId,
        int $number,
        string $status,
        mixed $calledAt = null,
        mixed $servedAt = null,
    ): int {
        return DB::table('queues')->insertGetId([
            'store_id' => $storeId,
            'queue_session_id' => $sessionId,
            'queue_number' => $number,
            'formatted_number' => 'A-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
            'source' => 'nfc',
            'access_token_hash' => hash('sha256', bin2hex(random_bytes(20))),
            'status' => $status,
            'called_at' => $calledAt?->format('Y-m-d H:i:s'),
            'served_at' => $servedAt?->format('Y-m-d H:i:s'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function token(): string
    {
        return bin2hex(random_bytes(20));
    }
}
