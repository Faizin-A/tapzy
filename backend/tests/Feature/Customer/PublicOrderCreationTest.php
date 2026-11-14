<?php

namespace Tests\Feature\Customer;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithPrefixedMySqlDatabase;
use Tests\TestCase;
use Throwable;

class PublicOrderCreationTest extends TestCase
{
    use InteractsWithPrefixedMySqlDatabase;

    private const ISOLATED_TABLES = [
        'order_items',
        'orders',
        'products',
        'queues',
        'queue_sessions',
        'nfc_cards',
        'stores',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPrefixedMySqlDatabase('cust_order');

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

            Schema::create('products', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('store_id');
                $table->string('name', 150);
                $table->text('description')->nullable();
                $table->decimal('price', 12, 2)->default(0.00);
                $table->string('category', 100)->nullable();
                $table->string('image_url', 255)->nullable();
                $table->boolean('is_available')->default(true);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
                $table->index('store_id');
                $table->index('category');
                $table->index(['store_id', 'is_active', 'is_available'], 'idx_store_product_catalog');
                $table->foreign('store_id', 'fk_product_store')->references('id')->on('stores')->restrictOnDelete()->cascadeOnUpdate();
            });

            Schema::create('orders', function (Blueprint $table): void {
                $table->id();
                $table->string('order_code', 50)->unique();
                $table->unsignedBigInteger('store_id');
                $table->unsignedBigInteger('queue_id')->nullable();
                $table->unsignedBigInteger('nfc_card_id')->nullable();
                $table->string('customer_name', 100)->nullable();
                $table->unsignedInteger('total_items')->default(0);
                $table->decimal('subtotal', 12, 2)->default(0.00);
                $table->decimal('tax_amount', 12, 2)->default(0.00);
                $table->decimal('discount_amount', 12, 2)->default(0.00);
                $table->decimal('total_price', 12, 2)->default(0.00);
                $table->enum('status', ['pending', 'confirmed', 'preparing', 'ready', 'completed', 'cancelled'])->default('pending');
                $table->timestamp('completed_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index('store_id');
                $table->unique('queue_id', 'uq_orders_queue_id');
                $table->index('nfc_card_id');
                $table->index('status');
                $table->index('completed_at');
                $table->index('deleted_at');
                $table->index(['store_id', 'status', 'created_at'], 'idx_store_order_search');
                $table->foreign('store_id', 'fk_order_store')->references('id')->on('stores')->restrictOnDelete()->cascadeOnUpdate();
                $table->foreign('queue_id', 'fk_order_queue')->references('id')->on('queues')->restrictOnDelete()->cascadeOnUpdate();
                $table->foreign('nfc_card_id', 'fk_order_card')->references('id')->on('nfc_cards')->nullOnDelete()->cascadeOnUpdate();
            });

            Schema::create('order_items', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('order_id');
                $table->unsignedBigInteger('product_id')->nullable();
                $table->string('product_name_snapshot', 150);
                $table->decimal('unit_price_snapshot', 12, 2)->default(0.00);
                $table->unsignedInteger('quantity')->default(1);
                $table->decimal('subtotal', 12, 2)->default(0.00);
                $table->string('notes', 255)->nullable();
                $table->timestamps();
                $table->index('order_id');
                $table->index('product_id');
                $table->foreign('order_id', 'fk_item_order')->references('id')->on('orders')->restrictOnDelete()->cascadeOnUpdate();
                $table->foreign('product_id', 'fk_item_product')->references('id')->on('products')->nullOnDelete()->cascadeOnUpdate();
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

    public function test_creates_order_with_server_calculated_totals_and_snapshots(): void
    {
        $fixture = $this->seedOrderableQueue();
        $coffee = $this->insertProduct($fixture->storeId, 'Kopi Susu', 22000);
        $matcha = $this->insertProduct($fixture->storeId, 'Matcha Latte', 15000);

        $response = $this->postJson("/api/v1/public/queues/{$fixture->accessToken}/orders", [
            'notes' => 'Tanpa gula',
            'items' => [
                [
                    'product_id' => $coffee,
                    'quantity' => 2,
                    'notes' => 'Less sugar',
                    'unit_price' => 1,
                    'subtotal' => 2,
                ],
                [
                    'product_id' => $matcha,
                    'quantity' => 1,
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Pesanan berhasil dibuat.')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.total_items', 3)
            ->assertJsonPath('data.subtotal', '59000.00')
            ->assertJsonPath('data.total_price', '59000.00')
            ->assertJsonPath('data.notes', 'Tanpa gula')
            ->assertJsonPath('data.items.0.product_name', 'Kopi Susu')
            ->assertJsonPath('data.items.0.unit_price', '22000.00')
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.subtotal', '44000.00')
            ->assertJsonPath('data.items.0.notes', 'Less sugar')
            ->assertJsonPath('data.items.1.product_name', 'Matcha Latte')
            ->assertJsonPath('data.items.1.unit_price', '15000.00')
            ->assertJsonPath('data.items.1.quantity', 1)
            ->assertJsonPath('data.items.1.subtotal', '15000.00')
            ->assertJsonPath('data.items.1.notes', null)
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.store_id')
            ->assertJsonMissingPath('data.queue_id')
            ->assertJsonMissingPath('data.nfc_card_id')
            ->assertJsonMissingPath('data.customer_name')
            ->assertJsonMissingPath('data.customer_phone')
            ->assertJsonMissingPath('data.items.0.id')
            ->assertJsonMissingPath('data.items.0.product_id');

        $order = DB::table('orders')->where('queue_id', $fixture->queueId)->first();

        $this->assertNotNull($order);
        $this->assertSame($fixture->storeId, (int) $order->store_id);
        $this->assertSame($fixture->cardId, (int) $order->nfc_card_id);
        $this->assertSame('Budi', $order->customer_name);
        $this->assertSame(3, (int) $order->total_items);
        $this->assertSame('59000.00', (string) $order->subtotal);
        $this->assertSame('59000.00', (string) $order->total_price);
        $this->assertSame('pending', $order->status);

        $items = DB::table('order_items')->where('order_id', $order->id)->orderBy('id')->get();
        $this->assertCount(2, $items);
        $this->assertSame('Kopi Susu', $items[0]->product_name_snapshot);
        $this->assertSame('22000.00', (string) $items[0]->unit_price_snapshot);
        $this->assertSame(2, (int) $items[0]->quantity);
        $this->assertSame('44000.00', (string) $items[0]->subtotal);
        $this->assertSame('Less sugar', $items[0]->notes);
        $this->assertSame('Matcha Latte', $items[1]->product_name_snapshot);
        $this->assertSame('15000.00', (string) $items[1]->unit_price_snapshot);
    }

    public function test_snapshot_is_not_changed_when_product_is_edited_later(): void
    {
        $fixture = $this->seedOrderableQueue();
        $productId = $this->insertProduct($fixture->storeId, 'Original Name', 10000);

        $this->postJson("/api/v1/public/queues/{$fixture->accessToken}/orders", [
            'items' => [['product_id' => $productId, 'quantity' => 1]],
        ])->assertCreated();

        DB::table('products')->where('id', $productId)->update([
            'name' => 'Changed Name',
            'price' => 99999,
        ]);

        $order = DB::table('orders')->where('queue_id', $fixture->queueId)->first();
        $item = DB::table('order_items')->where('order_id', $order->id)->first();

        $this->assertSame('Original Name', $item->product_name_snapshot);
        $this->assertSame('10000.00', (string) $item->unit_price_snapshot);
        $this->assertSame('10000.00', (string) $item->subtotal);
    }

    public function test_rejects_queue_only_store(): void
    {
        $fixture = $this->seedOrderableQueue(orderingMode: 'queue_only');
        $productId = $this->insertProduct($fixture->storeId, 'Kopi', 10000);

        $this->postJson("/api/v1/public/queues/{$fixture->accessToken}/orders", [
            'items' => [['product_id' => $productId, 'quantity' => 1]],
        ])->assertStatus(409)
            ->assertJsonPath('error_code', 'ORDERING_DISABLED');
    }

    public function test_rejects_inactive_and_expired_store(): void
    {
        $inactive = $this->seedOrderableQueue(isActive: false);
        $expired = $this->seedOrderableQueue(expiresAt: now()->subHour());
        $inactiveProduct = $this->insertProduct($inactive->storeId, 'Kopi', 10000);
        $expiredProduct = $this->insertProduct($expired->storeId, 'Kopi', 10000);

        $this->postJson("/api/v1/public/queues/{$inactive->accessToken}/orders", [
            'items' => [['product_id' => $inactiveProduct, 'quantity' => 1]],
        ])->assertStatus(409)
            ->assertJsonPath('error_code', 'STORE_NOT_OPERATIONAL');

        $this->postJson("/api/v1/public/queues/{$expired->accessToken}/orders", [
            'items' => [['product_id' => $expiredProduct, 'quantity' => 1]],
        ])->assertStatus(409)
            ->assertJsonPath('error_code', 'STORE_NOT_OPERATIONAL');
    }

    public function test_soft_deleted_store_returns_not_found(): void
    {
        $fixture = $this->seedOrderableQueue();
        $productId = $this->insertProduct($fixture->storeId, 'Kopi', 10000);

        DB::table('stores')->where('id', $fixture->storeId)->update(['deleted_at' => now()]);

        $this->postJson("/api/v1/public/queues/{$fixture->accessToken}/orders", [
            'items' => [['product_id' => $productId, 'quantity' => 1]],
        ])->assertNotFound();
    }

    public function test_rejects_inactive_session(): void
    {
        $fixture = $this->seedOrderableQueue();
        $productId = $this->insertProduct($fixture->storeId, 'Kopi', 10000);

        DB::table('queue_sessions')->where('id', $fixture->sessionId)->update([
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        $this->postJson("/api/v1/public/queues/{$fixture->accessToken}/orders", [
            'items' => [['product_id' => $productId, 'quantity' => 1]],
        ])->assertStatus(409)
            ->assertJsonPath('error_code', 'QUEUE_SESSION_INACTIVE');
    }

    public function test_allows_waiting_called_and_in_service_statuses(): void
    {
        $fixture = $this->seedOrderableQueue(createQueue: false);
        $productId = $this->insertProduct($fixture->storeId, 'Kopi', 10000);
        $number = 0;

        foreach (['waiting', 'called', 'in_service'] as $status) {
            $number++;
            $token = $this->token();
            $this->insertQueue(
                $fixture->storeId,
                $fixture->sessionId,
                $fixture->cardId,
                $token,
                $status,
                $number
            );

            $this->postJson("/api/v1/public/queues/{$token}/orders", [
                'items' => [['product_id' => $productId, 'quantity' => 1]],
            ])->assertCreated()
                ->assertJsonPath('data.status', 'pending');
        }
    }

    public function test_rejects_skipped_completed_and_cancelled_statuses(): void
    {
        $fixture = $this->seedOrderableQueue(createQueue: false);
        $productId = $this->insertProduct($fixture->storeId, 'Kopi', 10000);
        $number = 0;

        foreach (['skipped', 'completed', 'cancelled'] as $status) {
            $number++;
            $token = $this->token();
            $this->insertQueue(
                $fixture->storeId,
                $fixture->sessionId,
                $fixture->cardId,
                $token,
                $status,
                $number
            );

            $this->postJson("/api/v1/public/queues/{$token}/orders", [
                'items' => [['product_id' => $productId, 'quantity' => 1]],
            ])->assertStatus(409)
                ->assertJsonPath('error_code', 'QUEUE_NOT_ORDERABLE');
        }
    }

    public function test_invalid_queue_token_returns_not_found(): void
    {
        $this->postJson('/api/v1/public/queues/'.str_repeat('A', 40).'/orders', [
            'items' => [['product_id' => 1, 'quantity' => 1]],
        ])->assertNotFound();
    }

    public function test_rejects_product_from_another_store(): void
    {
        $fixture = $this->seedOrderableQueue();
        $otherStore = $this->createStore();
        $otherProduct = $this->insertProduct($otherStore, 'Produk Toko Lain', 10000);

        $this->postJson("/api/v1/public/queues/{$fixture->accessToken}/orders", [
            'items' => [['product_id' => $otherProduct, 'quantity' => 1]],
        ])->assertStatus(422)
            ->assertJsonPath('error_code', 'PRODUCT_NOT_AVAILABLE');
    }

    public function test_rejects_inactive_unavailable_and_soft_deleted_products(): void
    {
        $fixture = $this->seedOrderableQueue();
        $inactive = $this->insertProduct($fixture->storeId, 'Nonaktif', 10000, active: false);
        $unavailable = $this->insertProduct($fixture->storeId, 'Habis', 10000, available: false);
        $deleted = $this->insertProduct($fixture->storeId, 'Dihapus', 10000, deletedAt: now());

        foreach ([$inactive, $unavailable, $deleted] as $productId) {
            $this->postJson("/api/v1/public/queues/{$fixture->accessToken}/orders", [
                'items' => [['product_id' => $productId, 'quantity' => 1]],
            ])->assertStatus(422)
                ->assertJsonPath('error_code', 'PRODUCT_NOT_AVAILABLE');
        }
    }

    public function test_rejects_second_order_for_same_queue(): void
    {
        $fixture = $this->seedOrderableQueue();
        $productId = $this->insertProduct($fixture->storeId, 'Kopi', 10000);
        $payload = ['items' => [['product_id' => $productId, 'quantity' => 1]]];

        $this->postJson("/api/v1/public/queues/{$fixture->accessToken}/orders", $payload)
            ->assertCreated();

        $this->postJson("/api/v1/public/queues/{$fixture->accessToken}/orders", $payload)
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'ORDER_ALREADY_EXISTS');
    }

    public function test_soft_deleted_order_still_blocks_second_order(): void
    {
        $fixture = $this->seedOrderableQueue();
        $productId = $this->insertProduct($fixture->storeId, 'Kopi', 10000);
        $payload = ['items' => [['product_id' => $productId, 'quantity' => 1]]];

        $this->postJson("/api/v1/public/queues/{$fixture->accessToken}/orders", $payload)
            ->assertCreated();

        DB::table('orders')->where('queue_id', $fixture->queueId)->update([
            'deleted_at' => now(),
        ]);

        $this->postJson("/api/v1/public/queues/{$fixture->accessToken}/orders", $payload)
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'ORDER_ALREADY_EXISTS');
    }

    public function test_validates_item_and_order_limits(): void
    {
        $this->postJson('/api/v1/public/queues/'.$this->token().'/orders', [
            'items' => [],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('items');

        $tooManyItems = [];

        for ($i = 1; $i <= 51; $i++) {
            $tooManyItems[] = ['product_id' => $i, 'quantity' => 1];
        }

        $this->postJson('/api/v1/public/queues/'.$this->token().'/orders', [
            'items' => $tooManyItems,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('items');

        $this->postJson('/api/v1/public/queues/'.$this->token().'/orders', [
            'items' => [['product_id' => 1, 'quantity' => 0]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('items.0.quantity');

        $this->postJson('/api/v1/public/queues/'.$this->token().'/orders', [
            'items' => [['product_id' => 1, 'quantity' => 100]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('items.0.quantity');

        $this->postJson('/api/v1/public/queues/'.$this->token().'/orders', [
            'items' => [['product_id' => 1, 'quantity' => 1, 'notes' => str_repeat('a', 256)]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('items.0.notes');

        $this->postJson('/api/v1/public/queues/'.$this->token().'/orders', [
            'notes' => str_repeat('a', 1001),
            'items' => [['product_id' => 1, 'quantity' => 1]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('notes');

        $this->postJson('/api/v1/public/queues/'.$this->token().'/orders', [
            'items' => [
                ['product_id' => 1, 'quantity' => 1],
                ['product_id' => 1, 'quantity' => 1],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('items.0.product_id');
    }

    public function test_rate_limiting_enforced_per_queue_token_and_ip(): void
    {
        $token = $this->token();
        $payload = ['items' => [['product_id' => 1, 'quantity' => 1]]];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/api/v1/public/queues/{$token}/orders", $payload)->assertNotFound();
        }

        $this->postJson("/api/v1/public/queues/{$token}/orders", $payload)->assertStatus(429);
    }

    public function test_response_does_not_expose_internal_ids_or_pii(): void
    {
        $fixture = $this->seedOrderableQueue();
        $productId = $this->insertProduct($fixture->storeId, 'Kopi', 10000);

        $response = $this->postJson("/api/v1/public/queues/{$fixture->accessToken}/orders", [
            'items' => [['product_id' => $productId, 'quantity' => 1]],
        ])->assertCreated();

        $this->assertArrayNotHasKey('id', $response->json('data'));
        $this->assertArrayNotHasKey('store_id', $response->json('data'));
        $this->assertArrayNotHasKey('queue_id', $response->json('data'));
        $this->assertArrayNotHasKey('nfc_card_id', $response->json('data'));
        $this->assertArrayNotHasKey('customer_name', $response->json('data'));
        $this->assertArrayNotHasKey('customer_phone', $response->json('data'));
        $this->assertArrayNotHasKey('product_id', $response->json('data.items.0'));
    }

    private function seedOrderableQueue(
        bool $isActive = true,
        mixed $expiresAt = null,
        string $orderingMode = 'queue_and_order',
        bool $createQueue = true,
    ): object {
        $storeId = $this->createStore($isActive, $expiresAt, $orderingMode);
        $cardId = $this->insertCard($storeId);
        $sessionId = $this->insertSession($storeId);
        $accessToken = $this->token();
        $queueId = $createQueue
            ? $this->insertQueue($storeId, $sessionId, $cardId, $accessToken, 'waiting', 1)
            : null;

        return (object) [
            'storeId' => $storeId,
            'cardId' => $cardId,
            'sessionId' => $sessionId,
            'queueId' => $queueId,
            'accessToken' => $accessToken,
        ];
    }

    private function createStore(
        bool $isActive = true,
        mixed $expiresAt = null,
        string $orderingMode = 'queue_and_order',
    ): int {
        return DB::table('stores')->insertGetId([
            'name' => 'Store '.Str::random(5),
            'slug' => 'store-'.Str::random(8),
            'avg_service_time' => 10,
            'queue_prefix' => 'A',
            'ordering_mode' => $orderingMode,
            'is_active' => $isActive,
            'expires_at' => $expiresAt
                ? $expiresAt->utc()->format('Y-m-d H:i:s')
                : now()->addYear()->utc()->format('Y-m-d H:i:s'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertCard(int $storeId): int
    {
        return DB::table('nfc_cards')->insertGetId([
            'store_id' => $storeId,
            'card_uid' => 'UID-'.Str::random(12),
            'public_token_hash' => hash('sha256', $this->token()),
            'label' => 'Meja 1',
            'type' => 'table',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertSession(int $storeId): int
    {
        return DB::table('queue_sessions')->insertGetId([
            'store_id' => $storeId,
            'session_code' => 'QS-'.Str::random(8),
            'session_date' => now()->toDateString(),
            'last_queue_number' => 0,
            'status' => 'active',
            'opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertQueue(
        int $storeId,
        int $sessionId,
        ?int $cardId,
        string $accessToken,
        string $status = 'waiting',
        int $queueNumber = 1,
        ?string $customerName = 'Budi',
    ): int {
        return DB::table('queues')->insertGetId([
            'store_id' => $storeId,
            'queue_session_id' => $sessionId,
            'nfc_card_id' => $cardId,
            'queue_number' => $queueNumber,
            'formatted_number' => sprintf('A-%03d', $queueNumber),
            'customer_name' => $customerName,
            'customer_phone' => '6281234567890',
            'source' => 'nfc',
            'access_token_hash' => hash('sha256', $accessToken),
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertProduct(
        int $storeId,
        string $name,
        float $price,
        bool $active = true,
        bool $available = true,
        mixed $deletedAt = null,
    ): int {
        return DB::table('products')->insertGetId([
            'store_id' => $storeId,
            'name' => $name,
            'description' => null,
            'price' => $price,
            'category' => 'Minuman',
            'image_url' => null,
            'is_available' => $available,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $deletedAt,
        ]);
    }

    private function token(): string
    {
        return bin2hex(random_bytes(20));
    }
}
