<?php

namespace Tests\Feature\Customer;

use App\Models\Queue;
use App\Models\QueueStatusHistory;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithPrefixedMySqlDatabase;
use Tests\TestCase;
use Throwable;

class CustomerQueueCreationTest extends TestCase
{
    use InteractsWithPrefixedMySqlDatabase;

    private const ISOLATED_TABLES = [
        'queue_status_histories',
        'queues',
        'queue_sessions',
        'nfc_cards',
        'users',
        'stores',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('public-queue-create:');
        $this->setUpPrefixedMySqlDatabase('cqc');

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

            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('store_id')->nullable();
                $table->string('name', 150);
                $table->string('email', 150)->unique();
                $table->string('password', 255);
                $table->enum('role', ['super_admin', 'merchant'])->default('merchant');
                $table->timestamps();
                $table->softDeletes();
                $table->foreign('store_id', 'fk_u_store')->references('id')->on('stores')->nullOnDelete();
            });

            Schema::create('nfc_cards', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('store_id');
                $table->string('card_uid', 100)->unique();
                $table->char('public_token_hash', 64)->unique();
                $table->string('label', 100);
                $table->string('type')->default('table');
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('activated_at')->nullable();
                $table->timestamp('deactivated_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unsignedBigInteger('active_store_id')
                    ->nullable()
                    ->virtualAs('CASE WHEN deleted_at IS NULL THEN store_id ELSE NULL END');
                $table->unique('active_store_id');
                $table->foreign('store_id', 'fk_card_store')->references('id')->on('stores')->restrictOnDelete();
            });

            Schema::create('queue_sessions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('store_id');
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
                $table->foreign('store_id', 'fk_qs_store')->references('id')->on('stores')->restrictOnDelete()->cascadeOnUpdate();
            });

            Schema::create('queues', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('store_id');
                $table->unsignedBigInteger('queue_session_id');
                $table->unsignedBigInteger('nfc_card_id')->nullable();
                $table->unsignedInteger('queue_number');
                $table->string('formatted_number', 20);
                $table->string('customer_name', 100)->nullable();
                $table->string('customer_phone', 30)->nullable();
                $table->enum('source', ['nfc', 'barcode', 'manual'])->default('nfc');
                $table->char('access_token_hash', 64);
                $table->text('access_token_ciphertext')->nullable();
                $table->enum('status', ['waiting', 'called', 'in_service', 'skipped', 'completed', 'cancelled'])->default('waiting');
                $table->timestamp('called_at')->nullable();
                $table->timestamp('served_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->text('notes')->nullable();
                $table->char('idempotency_key_hash', 64)->nullable();
                $table->char('request_payload_hash', 64)->nullable();
                $table->timestamps();
                $table->index('store_id');
                $table->index('queue_session_id');
                $table->index('nfc_card_id');
                $table->index('status');
                $table->unique(['queue_session_id', 'queue_number'], 'uq_session_queue_num');
                $table->unique('access_token_hash', 'uq_queue_access_token_hash');
                $table->unique(['store_id', 'idempotency_key_hash'], 'uq_store_queue_idempotency');
                $table->index(['store_id', 'status', 'created_at'], 'idx_store_queue_status_time');
                $table->foreign('store_id', 'fk_q_store')->references('id')->on('stores')->restrictOnDelete()->cascadeOnUpdate();
                $table->foreign('queue_session_id', 'fk_q_session')->references('id')->on('queue_sessions')->restrictOnDelete()->cascadeOnUpdate();
                $table->foreign('nfc_card_id', 'fk_q_card')->references('id')->on('nfc_cards')->nullOnDelete()->cascadeOnUpdate();
            });

            Schema::create('queue_status_histories', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('queue_id');
                $table->enum('previous_status', ['waiting', 'called', 'in_service', 'skipped', 'completed', 'cancelled'])->nullable()->default(null);
                $table->enum('new_status', ['waiting', 'called', 'in_service', 'skipped', 'completed', 'cancelled']);
                $table->unsignedBigInteger('changed_by_user_id')->nullable();
                $table->string('notes', 255)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index('queue_id');
                $table->index('changed_by_user_id');
                $table->index('new_status');
                $table->index('created_at');
                $table->foreign('queue_id', 'fk_qsh_queue')->references('id')->on('queues')->restrictOnDelete()->cascadeOnUpdate();
                $table->foreign('changed_by_user_id', 'fk_qsh_user')->references('id')->on('users')->nullOnDelete()->cascadeOnUpdate();
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

    public function test_can_create_queue_with_minimal_payload(): void
    {
        $plainToken = Str::random(40);
        $fixture = $this->seedStoreWithActiveSessionAndCard($plainToken);

        $idempotencyKey = (string) Str::uuid();

        $response = $this->withHeaders([
            'Idempotency-Key' => $idempotencyKey,
            'Accept' => 'application/json',
        ])->postJson("/api/v1/public/stores/{$plainToken}/queues", []);

        $response->assertStatus(201)
            ->assertHeaderMissing('Idempotency-Key')
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Antrean berhasil diambil.')
            ->assertJsonPath('data.queue_number', 1)
            ->assertJsonPath('data.formatted_number', 'A-001')
            ->assertJsonPath('data.session_code', 'QS-TEST-001')
            ->assertJsonPath('data.status', 'waiting')
            ->assertJsonPath('data.customer_name', null)
            ->assertJsonPath('data.customer_phone', null)
            ->assertJsonPath('data.waiting_ahead', 0)
            ->assertJsonPath('data.estimated_wait_minutes', 0);

        $plainAccessToken = $response->json('data.access_token');
        $this->assertIsString($plainAccessToken);
        $this->assertSame(40, strlen($plainAccessToken));

        // Verify stored in DB
        $stored = Queue::withoutGlobalScope(TenantScope::class)->where('store_id', $fixture->storeId)->first();
        $this->assertNotNull($stored);
        $this->assertSame('nfc', $stored->source);
        $this->assertSame(hash('sha256', $plainAccessToken), $stored->access_token_hash);
        $this->assertNotNull($stored->access_token_ciphertext);
        $this->assertStringNotContainsString($plainAccessToken, $stored->access_token_ciphertext);
        $this->assertSame(hash('sha256', $idempotencyKey), $stored->idempotency_key_hash);

        // Verify queue status history (bypass TenantScope — public context has no tenant)
        $history = QueueStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('queue_id', $stored->id)
            ->first();
        $this->assertNotNull($history);
        $this->assertNull($history->previous_status);
        $this->assertSame('waiting', $history->new_status);
        $this->assertNull($history->changed_by_user_id);
        $this->assertSame('Tiket antrean dibuat oleh pelanggan melalui NFC/QR', $history->notes);
    }

    public function test_can_create_queue_with_full_payload_and_phone_normalization(): void
    {
        $plainToken = Str::random(40);
        $fixture = $this->seedStoreWithActiveSessionAndCard($plainToken);

        $idempotencyKey = (string) Str::uuid();

        $response = $this->withHeaders([
            'Idempotency-Key' => $idempotencyKey,
            'Accept' => 'application/json',
        ])->postJson("/api/v1/public/stores/{$plainToken}/queues", [
            'customer_name' => 'Budi Santoso',
            'customer_phone' => '0812-3456-7890',
            'source' => 'barcode',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.customer_name', 'Budi Santoso')
            ->assertJsonPath('data.customer_phone', '6281234567890');

        $stored = Queue::withoutGlobalScope(TenantScope::class)->where('store_id', $fixture->storeId)->first();
        $this->assertNotNull($stored);
        $this->assertSame('Budi Santoso', $stored->customer_name);
        $this->assertSame('6281234567890', $stored->customer_phone);
        $this->assertSame('barcode', $stored->source);
    }

    public function test_phone_number_supports_plus_62_prefix_normalization(): void
    {
        $plainToken = Str::random(40);
        $fixture = $this->seedStoreWithActiveSessionAndCard($plainToken);

        $response = $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
            'Accept' => 'application/json',
        ])->postJson("/api/v1/public/stores/{$plainToken}/queues", [
            'customer_phone' => '+62 819 8765 4321',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.customer_phone', '6281987654321');

        $stored = Queue::withoutGlobalScope(TenantScope::class)->where('store_id', $fixture->storeId)->first();
        $this->assertSame('6281987654321', $stored->customer_phone);
    }

    public function test_sequential_queue_number_increment(): void
    {
        $plainToken = Str::random(40);
        $fixture = $this->seedStoreWithActiveSessionAndCard($plainToken);

        // First ticket
        $res1 = $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
            'Accept' => 'application/json',
        ])->postJson("/api/v1/public/stores/{$plainToken}/queues", [
            'customer_name' => 'First Customer',
        ]);

        $res1->assertStatus(201)
            ->assertJsonPath('data.queue_number', 1)
            ->assertJsonPath('data.formatted_number', 'A-001');

        // Second ticket
        $res2 = $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
            'Accept' => 'application/json',
        ])->postJson("/api/v1/public/stores/{$plainToken}/queues", [
            'customer_name' => 'Second Customer',
        ]);

        $res2->assertStatus(201)
            ->assertJsonPath('data.queue_number', 2)
            ->assertJsonPath('data.formatted_number', 'A-002');

        // Check last_queue_number on session
        $lastNumber = DB::table('queue_sessions')->where('id', $fixture->sessionId)->value('last_queue_number');
        $this->assertSame(2, $lastNumber);
    }

    public function test_waiting_ahead_only_counts_waiting_status_and_excludes_current_ticket(): void
    {
        $plainToken = Str::random(40);
        $fixture = $this->seedStoreWithActiveSessionAndCard($plainToken);

        // Pre-seed some queues in the session with various statuses
        // 1. Waiting
        $this->insertManualQueue($fixture->storeId, $fixture->sessionId, 1, 'waiting');
        // 2. In service (must NOT be counted)
        $this->insertManualQueue($fixture->storeId, $fixture->sessionId, 2, 'in_service');
        // 3. Called (must NOT be counted)
        $this->insertManualQueue($fixture->storeId, $fixture->sessionId, 3, 'called');
        // 4. Completed (must NOT be counted)
        $this->insertManualQueue($fixture->storeId, $fixture->sessionId, 4, 'completed');
        // 5. Cancelled (must NOT be counted)
        $this->insertManualQueue($fixture->storeId, $fixture->sessionId, 5, 'cancelled');
        // 6. Another Waiting
        $this->insertManualQueue($fixture->storeId, $fixture->sessionId, 6, 'waiting');

        // Update session last_queue_number to 6
        DB::table('queue_sessions')->where('id', $fixture->sessionId)->update(['last_queue_number' => 6]);

        // Store avg_service_time is 10 min. Exactly 2 tickets ahead are 'waiting'.
        $response = $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
            'Accept' => 'application/json',
        ])->postJson("/api/v1/public/stores/{$plainToken}/queues", [
            'customer_name' => 'New Customer',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.queue_number', 7)
            ->assertJsonPath('data.formatted_number', 'A-007')
            ->assertJsonPath('data.waiting_ahead', 2)
            ->assertJsonPath('data.estimated_wait_minutes', 20); // 2 * 10
    }

    public function test_idempotent_replay_with_same_key_returns_200_and_original_access_token(): void
    {
        $plainToken = Str::random(40);
        $fixture = $this->seedStoreWithActiveSessionAndCard($plainToken);

        $idempotencyKey = (string) Str::uuid();

        // First request: 201 Created
        $first = $this->withHeaders([
            'Idempotency-Key' => $idempotencyKey,
            'Accept' => 'application/json',
        ])->postJson("/api/v1/public/stores/{$plainToken}/queues", [
            'customer_name' => 'Repeated Customer',
            'customer_phone' => '081234567890',
        ]);

        $first->assertStatus(201);
        $firstPlainToken = $first->json('data.access_token');
        $this->assertNotNull($firstPlainToken);

        // Replay with identical payload & same key: 200 OK
        $replay = $this->withHeaders([
            'Idempotency-Key' => $idempotencyKey,
            'Accept' => 'application/json',
        ])->postJson("/api/v1/public/stores/{$plainToken}/queues", [
            'customer_name' => 'Repeated Customer',
            'customer_phone' => '081234567890',
        ]);

        $replay->assertStatus(200)
            ->assertHeaderMissing('Idempotency-Key')
            ->assertHeader('X-Idempotent-Replay', 'true')
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Permintaan antrean telah diproses sebelumnya.')
            ->assertJsonPath('data.queue_number', 1)
            ->assertJsonPath('data.formatted_number', 'A-001')
            ->assertJsonPath('data.access_token', $firstPlainToken);

        // Assert exactly one queue record exists
        $count = Queue::withoutGlobalScope(TenantScope::class)->where('store_id', $fixture->storeId)->count();
        $this->assertSame(1, $count);
    }

    public function test_replay_with_unrecoverable_ciphertext_returns_generic_conflict(): void
    {
        $plainToken = Str::random(40);
        $fixture = $this->seedStoreWithActiveSessionAndCard($plainToken);
        $idempotencyKey = (string) Str::uuid();

        $this->withHeaders(['Idempotency-Key' => $idempotencyKey, 'Accept' => 'application/json'])
            ->postJson("/api/v1/public/stores/{$plainToken}/queues", [])
            ->assertStatus(201);

        DB::table('queues')->where('store_id', $fixture->storeId)->update([
            'access_token_ciphertext' => 'invalid-ciphertext',
        ]);

        $this->withHeaders(['Idempotency-Key' => $idempotencyKey, 'Accept' => 'application/json'])
            ->postJson("/api/v1/public/stores/{$plainToken}/queues", [])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'IDEMPOTENCY_RECOVERY_UNAVAILABLE');

        $this->assertSame(1, Queue::withoutGlobalScope(TenantScope::class)->where('store_id', $fixture->storeId)->count());
    }

    public function test_idempotency_payload_mismatch_returns_409_conflict(): void
    {
        $plainToken = Str::random(40);
        $fixture = $this->seedStoreWithActiveSessionAndCard($plainToken);

        $idempotencyKey = (string) Str::uuid();

        // First request
        $this->withHeaders([
            'Idempotency-Key' => $idempotencyKey,
            'Accept' => 'application/json',
        ])->postJson("/api/v1/public/stores/{$plainToken}/queues", [
            'customer_name' => 'Original Customer',
        ])->assertStatus(201);

        // Second request with same key but different customer_name
        $mismatch = $this->withHeaders([
            'Idempotency-Key' => $idempotencyKey,
            'Accept' => 'application/json',
        ])->postJson("/api/v1/public/stores/{$plainToken}/queues", [
            'customer_name' => 'Different Customer',
        ]);

        $mismatch->assertStatus(409)
            ->assertJsonPath('error_code', 'IDEMPOTENCY_PAYLOAD_MISMATCH');
    }

    public function test_replay_rejects_queue_session_from_another_store(): void
    {
        $plainToken = Str::random(40);
        $storeA = $this->seedStoreWithActiveSessionAndCard($plainToken);
        $otherToken = Str::random(40);
        $storeB = $this->seedStoreWithActiveSessionAndCard($otherToken);
        $idempotencyKey = (string) Str::uuid();

        $this->withHeaders(['Idempotency-Key' => $idempotencyKey, 'Accept' => 'application/json'])
            ->postJson("/api/v1/public/stores/{$plainToken}/queues", [])
            ->assertStatus(201);

        DB::table('queues')->where('store_id', $storeA->storeId)->update([
            'queue_session_id' => $storeB->sessionId,
        ]);

        $this->withHeaders(['Idempotency-Key' => $idempotencyKey, 'Accept' => 'application/json'])
            ->postJson("/api/v1/public/stores/{$plainToken}/queues", [])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'IDEMPOTENCY_RECOVERY_UNAVAILABLE')
            ->assertJsonMissingPath('data.session_code');
    }

    public function test_non_idempotency_unique_violation_is_not_treated_as_replay(): void
    {
        $plainToken = Str::random(40);
        $fixture = $this->seedStoreWithActiveSessionAndCard($plainToken);
        $this->insertManualQueue($fixture->storeId, $fixture->sessionId, 1, 'waiting');

        $this->withoutExceptionHandling();

        try {
            $this->withHeaders([
                'Idempotency-Key' => (string) Str::uuid(),
                'Accept' => 'application/json',
            ])->postJson("/api/v1/public/stores/{$plainToken}/queues", []);

            $this->fail('The queue number unique violation must be rethrown.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', $exception->getCode());
            $this->assertSame(1062, (int) ($exception->errorInfo[1] ?? 0));
            $this->assertStringContainsString('uq_session_queue_num', $exception->getMessage());
        }

        $this->assertSame(0, (int) DB::table('queue_sessions')->where('id', $fixture->sessionId)->value('last_queue_number'));
        $this->assertSame(1, DB::table('queues')->where('store_id', $fixture->storeId)->count());
        $this->assertSame(0, DB::table('queue_status_histories')->count());
    }

    public function test_fails_when_queue_session_is_inactive_or_closed_returns_409(): void
    {
        $plainToken = Str::random(40);
        $fixture = $this->seedStoreWithActiveSessionAndCard($plainToken);

        // Close the session
        DB::table('queue_sessions')->where('id', $fixture->sessionId)->update([
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        $response = $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
            'Accept' => 'application/json',
        ])->postJson("/api/v1/public/stores/{$plainToken}/queues", []);

        $response->assertStatus(409)
            ->assertJsonPath('error_code', 'QUEUE_SESSION_INACTIVE')
            ->assertJsonPath('message', 'Sesi antrean toko saat ini sedang tidak aktif atau belum dibuka.');
    }

    public function test_fails_when_store_has_no_session_at_all_returns_409(): void
    {
        $plainToken = Str::random(40);
        $fixture = $this->seedStoreWithActiveSessionAndCard($plainToken);

        // Delete all sessions for the store
        DB::table('queue_sessions')->where('store_id', $fixture->storeId)->delete();

        $response = $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
            'Accept' => 'application/json',
        ])->postJson("/api/v1/public/stores/{$plainToken}/queues", []);

        $response->assertStatus(409)
            ->assertJsonPath('error_code', 'QUEUE_SESSION_INACTIVE');
    }

    public function test_fails_when_token_is_unknown_returns_404(): void
    {
        $unknownToken = Str::random(40);

        $response = $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
            'Accept' => 'application/json',
        ])->postJson("/api/v1/public/stores/{$unknownToken}/queues", []);

        $response->assertStatus(404);
    }

    public function test_fails_when_card_is_inactive_returns_404(): void
    {
        $plainToken = Str::random(40);
        $fixture = $this->seedStoreWithActiveSessionAndCard($plainToken);

        DB::table('nfc_cards')->where('id', $fixture->cardId)->update(['is_active' => false]);

        $response = $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
            'Accept' => 'application/json',
        ])->postJson("/api/v1/public/stores/{$plainToken}/queues", []);

        $response->assertStatus(404);
    }

    public function test_fails_when_store_is_expired_returns_404(): void
    {
        $plainToken = Str::random(40);
        $fixture = $this->seedStoreWithActiveSessionAndCard($plainToken);

        DB::table('stores')->where('id', $fixture->storeId)->update([
            'expires_at' => now()->subDay()->format('Y-m-d H:i:s'),
        ]);

        $response = $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
            'Accept' => 'application/json',
        ])->postJson("/api/v1/public/stores/{$plainToken}/queues", []);

        $response->assertStatus(404);
    }

    public function test_phone_number_validation_rules(): void
    {
        $plainToken = Str::random(40);
        $this->seedStoreWithActiveSessionAndCard($plainToken);

        // Invalid: non-Indonesian / non-mobile prefix (e.g. 021)
        $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
            'Accept' => 'application/json',
        ])->postJson("/api/v1/public/stores/{$plainToken}/queues", [
            'customer_phone' => '0211234567',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['customer_phone']);

        // Invalid: contains letters
        $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
            'Accept' => 'application/json',
        ])->postJson("/api/v1/public/stores/{$plainToken}/queues", [
            'customer_phone' => '0812abcd1234',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['customer_phone']);

        // Invalid: too short
        $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
            'Accept' => 'application/json',
        ])->postJson("/api/v1/public/stores/{$plainToken}/queues", [
            'customer_phone' => '08123',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['customer_phone']);
    }

    public function test_source_validation_rejects_manual(): void
    {
        $plainToken = Str::random(40);
        $this->seedStoreWithActiveSessionAndCard($plainToken);

        $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
            'Accept' => 'application/json',
        ])->postJson("/api/v1/public/stores/{$plainToken}/queues", [
            'source' => 'manual',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['source']);
    }

    public function test_idempotency_key_header_is_required(): void
    {
        $plainToken = Str::random(40);
        $this->seedStoreWithActiveSessionAndCard($plainToken);

        // Missing Idempotency-Key header
        $response = $this->withHeaders([
            'Accept' => 'application/json',
        ])->postJson("/api/v1/public/stores/{$plainToken}/queues", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['idempotency_key']);

        // Wrong format (not UUID v4)
        $shortKeyResponse = $this->withHeaders([
            'Idempotency-Key' => 'abc',
            'Accept' => 'application/json',
        ])->postJson("/api/v1/public/stores/{$plainToken}/queues", []);

        $shortKeyResponse->assertStatus(422)
            ->assertJsonValidationErrors(['idempotency_key']);
    }

    public function test_tenant_isolation_strictly_binds_to_card_store(): void
    {
        $plainToken1 = Str::random(40);
        $fixture1 = $this->seedStoreWithActiveSessionAndCard($plainToken1);

        $plainToken2 = Str::random(40);
        $fixture2 = $this->seedStoreWithActiveSessionAndCard($plainToken2);

        $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
            'Accept' => 'application/json',
        ])->postJson("/api/v1/public/stores/{$plainToken1}/queues", [
            'customer_name' => 'Store 1 Customer',
        ])->assertStatus(201);

        // Verify Store 1 has 1 ticket, Store 2 has 0 tickets
        $countStore1 = Queue::withoutGlobalScope(TenantScope::class)->where('store_id', $fixture1->storeId)->count();
        $countStore2 = Queue::withoutGlobalScope(TenantScope::class)->where('store_id', $fixture2->storeId)->count();

        $this->assertSame(1, $countStore1);
        $this->assertSame(0, $countStore2);
    }

    public function test_sensitive_hashes_are_not_exposed_in_response(): void
    {
        $plainToken = Str::random(40);
        $this->seedStoreWithActiveSessionAndCard($plainToken);

        $response = $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
            'Accept' => 'application/json',
        ])->postJson("/api/v1/public/stores/{$plainToken}/queues", []);

        $response->assertStatus(201);
        $json = $response->json();

        $this->assertArrayNotHasKey('access_token_hash', $json['data']);
        $this->assertArrayNotHasKey('idempotency_key_hash', $json['data']);
        $this->assertArrayNotHasKey('request_payload_hash', $json['data']);
        $this->assertArrayNotHasKey('card_uid', $json['data']);
    }

    public function test_rate_limiting_enforcement(): void
    {
        $plainToken = Str::random(40);
        $this->seedStoreWithActiveSessionAndCard($plainToken);

        for ($i = 1; $i <= 5; $i++) {
            $res = $this->withHeaders([
                'Idempotency-Key' => (string) Str::uuid(),
                'Accept' => 'application/json',
            ])->postJson("/api/v1/public/stores/{$plainToken}/queues", []);

            $res->assertStatus(201);
        }

        // 6th request within same minute must hit 429
        $sixth = $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
            'Accept' => 'application/json',
        ])->postJson("/api/v1/public/stores/{$plainToken}/queues", []);

        $sixth->assertStatus(429);
    }

    public function test_idempotency_migration_up_and_down_lifecycle(): void
    {
        $migration = require database_path('migrations/2026_11_09_000000_add_idempotency_to_queues_table.php');

        // Running up when columns exist is safe and idempotent
        $migration->up();
        $this->assertTrue(Schema::hasColumn('queues', 'idempotency_key_hash'));
        $this->assertTrue(Schema::hasColumn('queues', 'request_payload_hash'));
        $this->assertTrue(Schema::hasIndex('queues', 'uq_store_queue_idempotency', 'unique'));

        // Test down
        $migration->down();
        $this->assertFalse(Schema::hasColumn('queues', 'idempotency_key_hash'));
        $this->assertFalse(Schema::hasColumn('queues', 'request_payload_hash'));

        // Re-run up to leave schema in consistent state
        $migration->up();
        $this->assertTrue(Schema::hasColumn('queues', 'idempotency_key_hash'));
        $this->assertTrue(Schema::hasColumn('queues', 'request_payload_hash'));
    }

    private function seedStoreWithActiveSessionAndCard(string $plainToken): object
    {
        $storeId = DB::table('stores')->insertGetId([
            'name' => 'Toko Enak '.Str::random(5),
            'slug' => 'toko-enak-'.Str::random(8),
            'avg_service_time' => 10,
            'queue_prefix' => 'A',
            'ordering_mode' => 'queue_and_order',
            'is_active' => true,
            'expires_at' => now()->addDays(30)->format('Y-m-d H:i:s'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cardId = DB::table('nfc_cards')->insertGetId([
            'store_id' => $storeId,
            'card_uid' => 'UID-'.Str::random(12),
            'public_token_hash' => hash('sha256', $plainToken),
            'label' => 'Meja 1',
            'type' => 'table',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sessionId = DB::table('queue_sessions')->insertGetId([
            'store_id' => $storeId,
            'session_code' => 'QS-TEST-001',
            'session_date' => now()->toDateString(),
            'last_queue_number' => 0,
            'status' => 'active',
            'opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (object) [
            'storeId' => $storeId,
            'cardId' => $cardId,
            'sessionId' => $sessionId,
        ];
    }

    private function insertManualQueue(int $storeId, int $sessionId, int $queueNumber, string $status): void
    {
        DB::table('queues')->insert([
            'store_id' => $storeId,
            'queue_session_id' => $sessionId,
            'nfc_card_id' => null,
            'queue_number' => $queueNumber,
            'formatted_number' => sprintf('A-%03d', $queueNumber),
            'customer_name' => 'Seeded '.$queueNumber,
            'customer_phone' => null,
            'source' => 'nfc',
            'access_token_hash' => hash('sha256', Str::random(40)),
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
