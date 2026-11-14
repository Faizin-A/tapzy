<?php

namespace Tests\Feature\Customer;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithPrefixedMySqlDatabase;
use Tests\TestCase;
use Throwable;

class PublicQueueTrackingTest extends TestCase
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

        $this->setUpPrefixedMySqlDatabase('cust_tracking');

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
            });

            Schema::create('queue_sessions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('store_id')->constrained()->restrictOnDelete()->cascadeOnUpdate();
                $table->string('session_code', 50);
                $table->date('session_date')->index();
                $table->unsignedInteger('last_queue_number')->default(0);
                $table->enum('status', ['active', 'closed'])->default('active')->index();
                $table->timestamp('opened_at')->useCurrent();
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();
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
                $table->unique(['queue_session_id', 'queue_number'], 'uq_session_queue_num');
                $table->unique('access_token_hash', 'uq_queue_access_token_hash');
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

    public function test_returns_tracking_snapshot_for_valid_token(): void
    {
        $store = $this->createStore(avgServiceTime: 10);
        $session = $this->createSession($store->id, lastQueueNumber: 5);
        $token = $this->token();

        $this->insertQueue($store->id, $session->id, 1, 'waiting');
        $this->insertQueue($store->id, $session->id, 2, 'waiting');
        $this->insertQueue($store->id, $session->id, 3, 'waiting');
        $queueId = $this->insertQueue($store->id, $session->id, 4, 'waiting', token: $token);

        $response = $this->getJson("/api/v1/public/queues/{$token}");

        $response->assertOk()
            ->assertJsonPath('data.queue_number', 4)
            ->assertJsonPath('data.formatted_number', 'A-004')
            ->assertJsonPath('data.status', 'waiting')
            ->assertJsonPath('data.waiting_ahead', 3)
            ->assertJsonPath('data.estimated_wait_minutes', 30)
            ->assertJsonPath('data.session.session_code', $session->session_code)
            ->assertJsonPath('data.session.is_active', true)
            ->assertJsonPath('data.store.name', $store->name)
            ->assertJsonPath('data.store.queue_prefix', 'A')
            ->assertJsonPath('data.called_at', null)
            ->assertJsonPath('data.served_at', null)
            ->assertJsonPath('data.completed_at', null)
            ->assertJsonPath('data.cancelled_at', null);

        $this->assertNotNull($response->json('data.created_at'));
        $this->assertSame($queueId, DB::table('queues')->where('id', $queueId)->value('id'));
    }

    public function test_returns_404_for_invalid_token_format(): void
    {
        $this->getJson('/api/v1/public/queues/too-short')->assertNotFound();
        $this->getJson('/api/v1/public/queues/'.str_repeat('!', 40))->assertNotFound();
        $this->getJson('/api/v1/public/queues/'.str_repeat('A', 39))->assertNotFound();
        $this->getJson('/api/v1/public/queues/'.str_repeat('A', 41))->assertNotFound();
    }

    public function test_returns_404_for_unknown_token(): void
    {
        $this->getJson('/api/v1/public/queues/'.str_repeat('Z', 40))->assertNotFound();
    }

    public function test_returns_only_the_queue_owned_by_the_token(): void
    {
        $store = $this->createStore();
        $session = $this->createSession($store->id, lastQueueNumber: 10);

        $tokenA = $this->token();
        $tokenB = $this->token();
        $this->insertQueue($store->id, $session->id, 1, 'waiting', token: $tokenA);
        $this->insertQueue($store->id, $session->id, 2, 'waiting', token: $tokenB);

        $this->getJson("/api/v1/public/queues/{$tokenA}")
            ->assertOk()
            ->assertJsonPath('data.queue_number', 1);

        $this->getJson("/api/v1/public/queues/{$tokenB}")
            ->assertOk()
            ->assertJsonPath('data.queue_number', 2);
    }

    public function test_returns_all_queue_statuses_unchanged(): void
    {
        $store = $this->createStore();
        $session = $this->createSession($store->id, lastQueueNumber: 10);

        $statuses = ['waiting', 'called', 'in_service', 'skipped', 'completed', 'cancelled'];

        foreach ($statuses as $index => $status) {
            $token = $this->token();
            $this->insertQueue($store->id, $session->id, $index + 1, $status, token: $token);

            $this->getJson("/api/v1/public/queues/{$token}")
                ->assertOk()
                ->assertJsonPath('data.status', $status);
        }
    }

    public function test_waiting_ahead_only_counts_waiting_tickets_with_lower_number_in_same_session(): void
    {
        $store = $this->createStore(avgServiceTime: 7);
        $session = $this->createSession($store->id, lastQueueNumber: 20);
        $otherSession = $this->createSession($store->id, lastQueueNumber: 20, status: 'closed');
        $otherStore = $this->createStore(avgServiceTime: 99);
        $otherStoreSession = $this->createSession($otherStore->id, lastQueueNumber: 20);

        $token = $this->token();

        // Counted: waiting with lower number in the same session.
        $this->insertQueue($store->id, $session->id, 1, 'waiting');
        $this->insertQueue($store->id, $session->id, 2, 'waiting');

        // Excluded: non-waiting statuses with lower number.
        $this->insertQueue($store->id, $session->id, 3, 'called');
        $this->insertQueue($store->id, $session->id, 4, 'in_service');
        $this->insertQueue($store->id, $session->id, 5, 'skipped');
        $this->insertQueue($store->id, $session->id, 6, 'completed');
        $this->insertQueue($store->id, $session->id, 7, 'cancelled');

        // Target ticket.
        $this->insertQueue($store->id, $session->id, 8, 'waiting', token: $token);

        // Excluded: higher number in the same session.
        $this->insertQueue($store->id, $session->id, 9, 'waiting');

        // Excluded: same store but different session.
        $this->insertQueue($store->id, $otherSession->id, 1, 'waiting');

        // Excluded: different store.
        $this->insertQueue($otherStore->id, $otherStoreSession->id, 1, 'waiting');

        $this->getJson("/api/v1/public/queues/{$token}")
            ->assertOk()
            ->assertJsonPath('data.waiting_ahead', 2)
            ->assertJsonPath('data.estimated_wait_minutes', 14);
    }

    public function test_estimated_wait_is_zero_for_non_waiting_statuses(): void
    {
        $store = $this->createStore(avgServiceTime: 10);
        $session = $this->createSession($store->id, lastQueueNumber: 10);

        $this->insertQueue($store->id, $session->id, 1, 'waiting');
        $this->insertQueue($store->id, $session->id, 2, 'waiting');

        foreach (['called', 'in_service', 'skipped', 'completed', 'cancelled'] as $index => $status) {
            $token = $this->token();
            $this->insertQueue($store->id, $session->id, $index + 3, $status, token: $token);

            $this->getJson("/api/v1/public/queues/{$token}")
                ->assertOk()
                ->assertJsonPath('data.waiting_ahead', 2)
                ->assertJsonPath('data.estimated_wait_minutes', 0);
        }
    }

    public function test_estimated_wait_is_zero_when_session_is_closed(): void
    {
        $store = $this->createStore(avgServiceTime: 10);
        $session = $this->createSession($store->id, lastQueueNumber: 10, status: 'closed');
        $token = $this->token();

        $this->insertQueue($store->id, $session->id, 1, 'waiting');
        $this->insertQueue($store->id, $session->id, 2, 'waiting', token: $token);

        $this->getJson("/api/v1/public/queues/{$token}")
            ->assertOk()
            ->assertJsonPath('data.waiting_ahead', 1)
            ->assertJsonPath('data.estimated_wait_minutes', 0)
            ->assertJsonPath('data.session.is_active', false);
    }

    public function test_ticket_is_trackable_when_store_is_inactive_or_expired(): void
    {
        $inactiveStore = $this->createStore(isActive: false);
        $inactiveSession = $this->createSession($inactiveStore->id, lastQueueNumber: 3);
        $inactiveToken = $this->token();
        $this->insertQueue($inactiveStore->id, $inactiveSession->id, 1, 'waiting', token: $inactiveToken);

        $this->getJson("/api/v1/public/queues/{$inactiveToken}")
            ->assertOk()
            ->assertJsonPath('data.queue_number', 1);

        $expiredStore = $this->createStore(expiresAt: now()->subHour());
        $expiredSession = $this->createSession($expiredStore->id, lastQueueNumber: 3);
        $expiredToken = $this->token();
        $this->insertQueue($expiredStore->id, $expiredSession->id, 1, 'waiting', token: $expiredToken);

        $this->getJson("/api/v1/public/queues/{$expiredToken}")
            ->assertOk()
            ->assertJsonPath('data.queue_number', 1);
    }

    public function test_returns_404_when_store_is_soft_deleted(): void
    {
        $store = $this->createStore();
        $session = $this->createSession($store->id, lastQueueNumber: 3);
        $token = $this->token();
        $this->insertQueue($store->id, $session->id, 1, 'waiting', token: $token);

        DB::table('stores')->where('id', $store->id)->update(['deleted_at' => now()]);

        $this->getJson("/api/v1/public/queues/{$token}")->assertNotFound();
    }

    public function test_tenant_isolation_does_not_count_other_store_queues(): void
    {
        $storeA = $this->createStore(avgServiceTime: 5);
        $sessionA = $this->createSession($storeA->id, lastQueueNumber: 10);
        $storeB = $this->createStore(avgServiceTime: 50);
        $sessionB = $this->createSession($storeB->id, lastQueueNumber: 10);

        $token = $this->token();
        $this->insertQueue($storeA->id, $sessionA->id, 1, 'waiting', token: $token);
        $this->insertQueue($storeB->id, $sessionB->id, 1, 'waiting');
        $this->insertQueue($storeB->id, $sessionB->id, 2, 'waiting');

        $this->getJson("/api/v1/public/queues/{$token}")
            ->assertOk()
            ->assertJsonPath('data.waiting_ahead', 0)
            ->assertJsonPath('data.estimated_wait_minutes', 0)
            ->assertJsonPath('data.store.name', $storeA->name);
    }

    public function test_response_does_not_expose_sensitive_fields(): void
    {
        $store = $this->createStore();
        $session = $this->createSession($store->id, lastQueueNumber: 3);
        $token = $this->token();
        $this->insertQueue($store->id, $session->id, 1, 'waiting', token: $token, customerName: 'Rahasia', customerPhone: '628123456789');

        $response = $this->getJson("/api/v1/public/queues/{$token}");

        $response->assertOk();
        $response->assertJsonMissingPath('data.id');
        $response->assertJsonMissingPath('data.store_id');
        $response->assertJsonMissingPath('data.queue_session_id');
        $response->assertJsonMissingPath('data.nfc_card_id');
        $response->assertJsonMissingPath('data.access_token_hash');
        $response->assertJsonMissingPath('data.customer_name');
        $response->assertJsonMissingPath('data.customer_phone');
        $response->assertJsonMissingPath('data.notes');
        $response->assertJsonMissingPath('data.is_session_active');
    }

    public function test_rate_limiting_enforced_per_token_and_ip(): void
    {
        $store = $this->createStore();
        $session = $this->createSession($store->id, lastQueueNumber: 3);
        $token = $this->token();
        $this->insertQueue($store->id, $session->id, 1, 'waiting', token: $token);

        for ($i = 0; $i < 60; $i++) {
            $this->getJson("/api/v1/public/queues/{$token}")->assertOk();
        }

        $this->getJson("/api/v1/public/queues/{$token}")->assertStatus(429);
    }

    public function test_rate_limiting_enforced_cumulative_per_ip(): void
    {
        // 6 distinct tokens x 50 requests = 300 requests, all under the
        // per-token+IP limit but exactly at the cumulative per-IP limit.
        for ($tokenIndex = 0; $tokenIndex < 6; $tokenIndex++) {
            $token = $this->token();

            for ($requestIndex = 0; $requestIndex < 50; $requestIndex++) {
                $this->getJson("/api/v1/public/queues/{$token}")->assertNotFound();
            }
        }

        // A fresh token must still be blocked by the cumulative per-IP limit.
        $this->getJson('/api/v1/public/queues/'.$this->token())->assertStatus(429);
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

    private function createSession(int $storeId, int $lastQueueNumber = 0, string $status = 'active'): object
    {
        $id = DB::table('queue_sessions')->insertGetId([
            'store_id' => $storeId,
            'session_code' => 'QS-'.bin2hex(random_bytes(4)),
            'session_date' => now()->toDateString(),
            'last_queue_number' => $lastQueueNumber,
            'status' => $status,
            'opened_at' => now(),
            'closed_at' => $status === 'closed' ? now() : null,
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
        ?string $token = null,
        ?string $customerName = null,
        ?string $customerPhone = null,
    ): int {
        $token ??= $this->token();

        return DB::table('queues')->insertGetId([
            'store_id' => $storeId,
            'queue_session_id' => $sessionId,
            'queue_number' => $number,
            'formatted_number' => 'A-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
            'customer_name' => $customerName,
            'customer_phone' => $customerPhone,
            'source' => 'nfc',
            'access_token_hash' => hash('sha256', $token),
            'status' => $status,
            'called_at' => in_array($status, ['called', 'in_service', 'completed'], true) ? now()->subMinutes(2) : null,
            'served_at' => in_array($status, ['in_service', 'completed'], true) ? now()->subMinute() : null,
            'completed_at' => $status === 'completed' ? now() : null,
            'cancelled_at' => $status === 'cancelled' ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function token(): string
    {
        return bin2hex(random_bytes(20));
    }
}
