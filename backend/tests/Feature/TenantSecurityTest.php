<?php

namespace Tests\Feature;

use App\Http\Requests\TenantOwnedResourceRequest;
use App\Models\AuditLog;
use App\Models\NfcCard;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Queue;
use App\Models\QueueSession;
use App\Models\QueueStatusHistory;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Exists;
use RuntimeException;
use Tests\TestCase;

class TenantSecurityTest extends TestCase
{
    private const SPA_ORIGIN = 'http://localhost:3000';

    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());

        config(['session.driver' => 'array']);
        config(['sanctum.stateful' => ['localhost:3000']]);

        foreach ([
            'queue_status_histories', 'order_items', 'audit_logs', 'orders', 'queues',
            'nfc_cards', 'queue_sessions', 'products', 'users', 'stores',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        $this->createTenantSchema();
        $this->registerTestRoutes();
    }

    public function test_store_policy_only_allows_merchant_access_to_own_store(): void
    {
        $storeA = $this->createStore('Store A');
        $storeB = $this->createStore('Store B');
        $merchant = $this->createMerchant($storeA, 'store-policy@example.test');

        $this->authenticate($merchant)
            ->getJson('/api/v1/tenant-security/merchant/stores/'.$storeA->id)
            ->assertOk()
            ->assertJsonPath('data.id', $storeA->id);

        $this->withSpaOrigin()
            ->getJson('/api/v1/tenant-security/merchant/stores/'.$storeB->id)
            ->assertForbidden();
    }

    public function test_merchant_cannot_activate_super_admin_platform_bypass(): void
    {
        $store = $this->createStore('Merchant Store');
        $merchant = $this->createMerchant($store, 'cannot-bypass@example.test');

        $this->authenticate($merchant)
            ->getJson('/api/v1/tenant-security/merchant/platform-products')
            ->assertForbidden();

        $this->assertNull(app(TenantContext::class)->storeId());
        $this->assertFalse(app(TenantContext::class)->allowsPlatformAccess());
    }

    public function test_super_admin_has_no_automatic_global_scope_bypass(): void
    {
        $this->createProduct($this->createStore('Store A'), 'A product');
        $this->createProduct($this->createStore('Store B'), 'B product');
        $admin = $this->createSuperAdmin('no-automatic-bypass@example.test');

        $this->authenticate($admin)
            ->getJson('/api/v1/tenant-security/admin/scoped-products')
            ->assertOk()
            ->assertJsonPath('data.ids', []);

        $this->assertFalse(app(TenantContext::class)->allowsPlatformAccess());
    }

    public function test_super_admin_reads_all_tenants_only_on_explicit_platform_route(): void
    {
        $this->createProduct($this->createStore('Platform Store A'), 'A product');
        $this->createProduct($this->createStore('Platform Store B'), 'B product');
        $admin = $this->createSuperAdmin('explicit-platform@example.test');

        $this->authenticate($admin)
            ->getJson('/api/v1/tenant-security/admin/platform-products')
            ->assertOk()
            ->assertJsonCount(2, 'data.ids');

        $this->assertFalse(app(TenantContext::class)->allowsPlatformAccess());
    }

    public function test_audit_and_history_creation_and_historical_deletion_are_denied(): void
    {
        $store = $this->createStore('Read-only audit store');
        $merchant = $this->createMerchant($store, 'no-audit-create@example.test');
        $queue = $this->createQueue($store, 'Q-001');
        $order = $this->createOrder($store, 'READ-ONLY-ORDER');
        $item = $this->createOrderItem($order, $this->createProduct($store, 'Snapshot product'));

        $this->authenticate($merchant)
            ->postJson('/api/v1/tenant-security/merchant/audit-logs')
            ->assertForbidden();

        $this->withSpaOrigin()
            ->postJson('/api/v1/tenant-security/merchant/queues/'.$queue->id.'/history')
            ->assertForbidden();

        $this->withSpaOrigin()
            ->getJson('/api/v1/tenant-security/merchant/queues/'.$queue->id.'/delete')
            ->assertForbidden();

        $this->withSpaOrigin()
            ->getJson('/api/v1/tenant-security/merchant/queue-sessions/'.$queue->queue_session_id.'/delete')
            ->assertForbidden();

        $this->withSpaOrigin()
            ->getJson('/api/v1/tenant-security/merchant/order-items/'.$item->id.'/delete')
            ->assertForbidden();

        $this->withSpaOrigin()
            ->getJson('/api/v1/tenant-security/merchant/products/'.$this->createProduct($store, 'Restore denied')->id.'/restore')
            ->assertForbidden();
    }

    public function test_order_item_must_belong_to_order_in_nested_route(): void
    {
        $storeA = $this->createStore('Order Item Store A');
        $merchant = $this->createMerchant($storeA, 'order-item-owner@example.test');
        $productA = $this->createProduct($storeA, 'A product');
        $orderA1 = $this->createOrder($storeA, 'A-ORDER-1');
        $orderA2 = $this->createOrder($storeA, 'A-ORDER-2');
        $itemA1 = $this->createOrderItem($orderA1, $productA);
        $itemA2 = $this->createOrderItem($orderA2, $productA);

        $storeB = $this->createStore('Order Item Store B');
        $productB = $this->createProduct($storeB, 'B product');
        $orderB = $this->createOrder($storeB, 'B-ORDER-1');
        $itemB = $this->createOrderItem($orderB, $productB);

        $this->authenticate($merchant)
            ->getJson('/api/v1/tenant-security/merchant/orders/'.$orderA1->id.'/items/'.$itemA1->id)
            ->assertOk()
            ->assertJsonPath('data.id', $itemA1->id);

        $this->withSpaOrigin()
            ->getJson('/api/v1/tenant-security/merchant/orders/'.$orderA1->id.'/items/'.$itemA2->id)
            ->assertForbidden();

        $this->withSpaOrigin()
            ->getJson('/api/v1/tenant-security/merchant/orders/'.$orderA1->id.'/items/'.$itemB->id)
            ->assertNotFound();

        $this->withSpaOrigin()
            ->postJson('/api/v1/tenant-security/merchant/orders/'.$orderA1->id.'/items', [
                'product_id' => $productB->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_id');
    }

    public function test_queue_status_history_must_belong_to_queue_in_nested_route(): void
    {
        $store = $this->createStore('History Store');
        $merchant = $this->createMerchant($store, 'history-owner@example.test');
        $queueA = $this->createQueue($store, 'A-001');
        $queueB = $this->createQueue($store, 'A-002');
        $historyA = $this->createHistory($queueA);
        $historyB = $this->createHistory($queueB);

        $this->authenticate($merchant)
            ->getJson('/api/v1/tenant-security/merchant/queues/'.$queueA->id.'/history/'.$historyA->id)
            ->assertOk()
            ->assertJsonPath('data.history_id', $historyA->id);

        $this->withSpaOrigin()
            ->getJson('/api/v1/tenant-security/merchant/queues/'.$queueA->id.'/history/'.$historyB->id)
            ->assertForbidden();
    }

    public function test_order_queue_card_and_queue_session_links_must_share_the_store(): void
    {
        $storeA = $this->createStore('Relations Store A');
        $merchant = $this->createMerchant($storeA, 'relation-owner@example.test');
        $sessionA = $this->createQueueSession($storeA, 'A-SESSION');
        $cardA = $this->createCard($storeA, 'A-CARD');
        $queueA = $this->createQueue($storeA, 'A-001', $sessionA, $cardA);
        $orderA = $this->createOrder($storeA, 'A-ORDER', $queueA, $cardA);

        $storeB = $this->createStore('Relations Store B');
        $sessionB = $this->createQueueSession($storeB, 'B-SESSION');
        $cardB = $this->createCard($storeB, 'B-CARD');
        $queueB = $this->createQueue($storeB, 'B-001', $sessionB, $cardB);

        $context = app(TenantContext::class);
        $context->setMerchantStore($merchant, $storeA);
        $this->assertTrue(Gate::forUser($merchant)->allows('create', Queue::class));
        $context->clear();

        $this->authenticate($merchant)
            ->postJson('/api/v1/tenant-security/merchant/orders/'.$orderA->id.'/links', [
                'queue_id' => $queueA->id,
                'nfc_card_id' => $cardA->id,
            ])
            ->assertOk();

        $this->withSpaOrigin()
            ->postJson('/api/v1/tenant-security/merchant/orders/'.$orderA->id.'/links', [
                'queue_id' => $queueB->id,
                'nfc_card_id' => $cardA->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('queue_id');

        $this->withSpaOrigin()
            ->postJson('/api/v1/tenant-security/merchant/orders/'.$orderA->id.'/links', [
                'queue_id' => $queueA->id,
                'nfc_card_id' => $cardB->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('nfc_card_id');

        $queueFromForeignSession = $this->withSpaOrigin()
            ->postJson('/api/v1/tenant-security/merchant/queues', [
                'queue_session_id' => $sessionB->id,
                'nfc_card_id' => $cardA->id,
            ]);
        $this->assertSame(422, $queueFromForeignSession->status(), $queueFromForeignSession->getContent());
        $queueFromForeignSession->assertJsonValidationErrors('queue_session_id');

        $this->withSpaOrigin()
            ->postJson('/api/v1/tenant-security/merchant/queues', [
                'queue_session_id' => $sessionA->id,
                'nfc_card_id' => $cardB->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('nfc_card_id');
    }

    public function test_store_id_client_input_does_not_change_context_and_is_rejected_by_form_request(): void
    {
        $storeA = $this->createStore('Input Store A');
        $merchant = $this->createMerchant($storeA, 'store-input@example.test');
        $productA = $this->createProduct($storeA, 'A product');
        $storeB = $this->createStore('Input Store B');
        $productB = $this->createProduct($storeB, 'B product');

        $this->authenticate($merchant)
            ->withHeader('X-Store-Id', (string) $storeB->id)
            ->getJson('/api/v1/tenant-security/merchant/products?store_id='.$storeB->id)
            ->assertOk()
            ->assertJsonPath('data.tenant_id', $storeA->id)
            ->assertJsonPath('data.ids.0', $productA->id)
            ->assertJsonMissing(['id' => $productB->id]);

        $this->withSpaOrigin()
            ->postJson('/api/v1/tenant-security/merchant/queues', [
                'store_id' => $storeB->id,
                'queue_session_id' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('store_id');
    }

    public function test_all_tenant_scopes_fail_closed_without_context(): void
    {
        $store = $this->createStore('Fail Closed Store');
        $session = $this->createQueueSession($store, 'FAIL-CLOSED-SESSION');
        $card = $this->createCard($store, 'FAIL-CLOSED-CARD');
        $queue = $this->createQueue($store, 'F-001', $session, $card);
        $product = $this->createProduct($store, 'Fail closed product');
        $order = $this->createOrder($store, 'FAIL-CLOSED-ORDER', $queue, $card);
        $this->createOrderItem($order, $product);
        $this->createHistory($queue);
        $this->createAuditLog($store);

        $this->assertSame(0, NfcCard::query()->count());
        $this->assertSame(0, QueueSession::query()->count());
        $this->assertSame(0, Queue::query()->count());
        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, OrderItem::query()->count());
        $this->assertSame(0, QueueStatusHistory::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_tenant_context_is_cleared_after_success_and_exception(): void
    {
        $store = $this->createStore('Context Store');
        $merchant = $this->createMerchant($store, 'context@example.test');
        $this->createProduct($store, 'Context product');

        $this->authenticate($merchant)
            ->getJson('/api/v1/tenant-security/merchant/context')
            ->assertOk()
            ->assertJsonPath('data.store_id', $store->id);
        $this->assertNull(app(TenantContext::class)->storeId());

        $this->withoutExceptionHandling();

        try {
            $this->withSpaOrigin()->getJson('/api/v1/tenant-security/merchant/exception');
            $this->fail('The test route must throw so middleware cleanup is exercised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('tenant-context-test-exception', $exception->getMessage());
        }

        $this->assertNull(app(TenantContext::class)->storeId());
        $this->assertFalse(app(TenantContext::class)->allowsPlatformAccess());
        $this->assertFalse(Product::query()->exists());
    }

    public function test_super_admin_platform_context_is_cleared_after_exception(): void
    {
        $this->createProduct($this->createStore('Admin Context Store'), 'Admin scoped product');
        $admin = $this->createSuperAdmin('admin-context@example.test');
        $this->authenticate($admin);
        $this->withoutExceptionHandling();

        try {
            $this->withSpaOrigin()->getJson('/api/v1/tenant-security/admin/platform-exception');
            $this->fail('The test route must throw so platform cleanup is exercised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('platform-context-test-exception', $exception->getMessage());
        }

        $this->assertFalse(app(TenantContext::class)->allowsPlatformAccess());
        $this->assertSame(0, Product::query()->count());
    }

    private function createTenantSchema(): void
    {
        Schema::create('stores', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 150)->unique();
            $table->boolean('is_active')->default(true);
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

        Schema::create('nfc_cards', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('card_uid', 100);
            $table->char('public_token_hash', 64);
            $table->string('label', 100);
            $table->string('type')->default('table');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('queue_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('session_code', 50);
            $table->date('session_date');
            $table->unsignedInteger('last_queue_number')->default(0);
            $table->string('status')->default('active');
            $table->unsignedBigInteger('active_store_id')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
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
            $table->string('source')->default('nfc');
            $table->char('access_token_hash', 64);
            $table->string('status')->default('waiting');
            $table->timestamp('called_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_code', 50)->unique();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('queue_id')->nullable();
            $table->unsignedBigInteger('nfc_card_id')->nullable();
            $table->string('customer_name', 100)->nullable();
            $table->unsignedInteger('total_items')->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total_price', 12, 2)->default(0);
            $table->string('status')->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('product_name_snapshot', 150);
            $table->decimal('unit_price_snapshot', 12, 2)->default(0);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->string('notes', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('queue_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('queue_id');
            $table->string('previous_status')->nullable();
            $table->string('new_status');
            $table->unsignedBigInteger('changed_by_user_id')->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
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

    private function registerTestRoutes(): void
    {
        Route::middleware('api')->prefix('api/v1/tenant-security')->group(function (): void {
            Route::middleware('dashboard.merchant')->group(function (): void {
                Route::get('/merchant/products', function () {
                    return response()->json([
                        'data' => [
                            'tenant_id' => app(TenantContext::class)->storeId(),
                            'ids' => Product::query()->orderBy('id')->pluck('id')->all(),
                        ],
                    ]);
                });

                Route::get('/merchant/stores/{store}', fn (Store $store) => response()->json([
                    'data' => ['id' => $store->id, 'name' => $store->name],
                ]))->middleware('can:view,store');

                Route::patch('/merchant/stores/{store}', fn (Store $store) => response()->json([
                    'data' => ['id' => $store->id],
                ]))->middleware('can:update,store');

                Route::get('/merchant/products/{product}', fn (Product $product) => response()->json([
                    'data' => ['id' => $product->id],
                ]))->middleware('can:view,product');

                Route::get('/merchant/orders/{order}/items/{item}', function (TestBoundOrderItemRequest $request, Order $order, OrderItem $item) {
                    return response()->json(['data' => ['id' => $item->id, 'order_id' => $order->id]]);
                });

                Route::post('/merchant/orders/{order}/items', function (TestCreateOrderItemRequest $request, Order $order) {
                    return response()->json(['data' => ['order_id' => $order->id]]);
                });

                Route::post('/merchant/orders/{order}/links', function (TestOrderRelationsRequest $request, Order $order) {
                    return response()->json(['data' => ['order_id' => $order->id]]);
                });

                Route::post('/merchant/queues', function (TestCreateQueueRequest $request) {
                    return response()->json(['data' => ['tenant_id' => app(TenantContext::class)->storeId()]]);
                });

                Route::get('/merchant/queues/{queue}/history/{history}', function (TestBoundHistoryRequest $request, Queue $queue, QueueStatusHistory $history) {
                    return response()->json(['data' => ['queue_id' => $queue->id, 'history_id' => $history->id]]);
                });

                Route::post('/merchant/audit-logs', function (TestAuditLogRequest $request) {
                    return response()->json(['data' => 'unexpected']);
                });

                Route::post('/merchant/queues/{queue}/history', function (TestQueueHistoryCreateRequest $request, Queue $queue) {
                    return response()->json(['data' => 'unexpected']);
                })->middleware('can:create,'.QueueStatusHistory::class);

                Route::get('/merchant/queues/{queue}/delete', fn (Queue $queue) => response()->json(['id' => $queue->id]))
                    ->middleware('can:delete,queue');

                Route::get('/merchant/order-items/{item}/delete', fn (OrderItem $item) => response()->json(['id' => $item->id]))
                    ->middleware('can:delete,item');

                Route::get('/merchant/queue-sessions/{session}/delete', fn (QueueSession $session) => response()->json(['id' => $session->id]))
                    ->middleware('can:delete,session');

                Route::get('/merchant/products/{product}/restore', fn (Product $product) => response()->json(['id' => $product->id]))
                    ->middleware('can:restore,product');

                Route::get('/merchant/platform-products', fn () => response()->json(['data' => Product::query()->pluck('id')->all()]))
                    ->middleware('tenant.platform');

                Route::get('/merchant/context', fn () => response()->json([
                    'data' => ['store_id' => app(TenantContext::class)->storeId()],
                ]));

                Route::get('/merchant/exception', function () {
                    throw new RuntimeException('tenant-context-test-exception');
                });
            });

            Route::middleware('dashboard.super_admin')->group(function (): void {
                Route::get('/admin/scoped-products', fn () => response()->json([
                    'data' => ['ids' => Product::query()->orderBy('id')->pluck('id')->all()],
                ]));

                Route::get('/admin/platform-products', fn () => response()->json([
                    'data' => ['ids' => Product::query()->orderBy('id')->pluck('id')->all()],
                ]))->middleware(['tenant.platform', 'can:viewAny,'.Product::class]);

                Route::get('/admin/platform-exception', function () {
                    throw new RuntimeException('platform-context-test-exception');
                })->middleware('tenant.platform');
            });
        });
    }

    private function authenticate(User $user): static
    {
        $this->withSpaOrigin()->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertOk();

        return $this;
    }

    private function withSpaOrigin(): static
    {
        return $this->withHeaders([
            'Origin' => 'http://localhost:3000',
            'Referer' => 'http://localhost:3000/',
        ]);
    }

    private function createStore(string $name): Store
    {
        return Store::create([
            'name' => $name,
            'slug' => 'store-'.bin2hex(random_bytes(6)),
            'is_active' => true,
        ]);
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

    private function createSuperAdmin(string $email): User
    {
        $user = new User([
            'name' => 'Platform administrator',
            'email' => $email,
            'password' => 'correct-password',
        ]);
        $user->role = 'super_admin';
        $user->store_id = null;
        $user->save();

        return $user;
    }

    private function createProduct(Store $store, string $name): Product
    {
        return $store->products()->create(['name' => $name, 'price' => '10.00']);
    }

    private function createCard(Store $store, string $uid): NfcCard
    {
        return $store->nfcCards()->create([
            'card_uid' => $uid,
            'public_token_hash' => hash('sha256', $uid.'-public'),
            'label' => $uid,
        ]);
    }

    private function createQueueSession(Store $store, string $code): QueueSession
    {
        return $store->queueSessions()->create([
            'session_code' => $code,
            'session_date' => '2026-11-06',
            'opened_at' => now(),
        ]);
    }

    private function createQueue(Store $store, string $number, ?QueueSession $session = null, ?NfcCard $card = null): Queue
    {
        $session ??= $this->createQueueSession($store, 'SESSION-'.bin2hex(random_bytes(4)));
        $queue = $session->queues()->make(['customer_name' => 'Customer']);
        $queue->store_id = $store->getKey();
        $queue->nfc_card_id = $card?->getKey();
        $queue->queue_number = random_int(1, 900000);
        $queue->formatted_number = $number;
        $queue->source = 'manual';
        $queue->access_token_hash = hash('sha256', $number.'-token');
        $queue->status = 'waiting';
        $queue->save();

        return $queue;
    }

    private function createOrder(Store $store, string $code, ?Queue $queue = null, ?NfcCard $card = null): Order
    {
        $order = $store->orders()->make(['customer_name' => 'Customer']);
        $order->order_code = $code;
        $order->store_id = $store->getKey();
        $order->queue_id = $queue?->getKey();
        $order->nfc_card_id = $card?->getKey();
        $order->total_items = 0;
        $order->subtotal = '0.00';
        $order->tax_amount = '0.00';
        $order->discount_amount = '0.00';
        $order->total_price = '0.00';
        $order->status = 'pending';
        $order->save();

        return $order;
    }

    private function createOrderItem(Order $order, Product $product): OrderItem
    {
        $item = $order->items()->make(['quantity' => 1]);
        $item->product_id = $product->getKey();
        $item->product_name_snapshot = $product->name;
        $item->unit_price_snapshot = $product->price;
        $item->subtotal = $product->price;
        $item->save();

        return $item;
    }

    private function createHistory(Queue $queue): QueueStatusHistory
    {
        $history = new QueueStatusHistory;
        $history->queue_id = $queue->getKey();
        $history->new_status = 'waiting';
        $history->save();

        return $history;
    }

    private function createAuditLog(Store $store): AuditLog
    {
        $audit = new AuditLog;
        $audit->store_id = $store->getKey();
        $audit->action = 'test.action';
        $audit->save();

        return $audit;
    }
}

class TestBoundOrderItemRequest extends TenantOwnedResourceRequest
{
    protected function resourceModelClass(): string
    {
        return OrderItem::class;
    }

    protected function boundParentChildRelations(): array
    {
        return [['parent' => 'order', 'relation' => 'items', 'child' => 'item']];
    }
}

class TestCreateOrderItemRequest extends TenantOwnedResourceRequest
{
    protected function resourceModelClass(): string
    {
        return OrderItem::class;
    }

    protected function resourceRules(): array
    {
        return [
            'order_id' => ['prohibited'],
            'product_id' => ['required', 'integer', $this->existsInTenant('products')],
        ];
    }
}

class TestOrderRelationsRequest extends TenantOwnedResourceRequest
{
    protected function resourceModelClass(): string
    {
        return Order::class;
    }

    protected function resourceRules(): array
    {
        return [
            'queue_id' => ['nullable', 'integer', $this->existsInTenant('queues')],
            'nfc_card_id' => ['nullable', 'integer', $this->existsInTenant('nfc_cards')],
        ];
    }
}

class TestCreateQueueRequest extends TenantOwnedResourceRequest
{
    protected function resourceModelClass(): string
    {
        return Queue::class;
    }

    protected function resourceRules(): array
    {
        return [
            'queue_session_id' => ['required', 'integer', $this->existsInTenant('queue_sessions')],
            'nfc_card_id' => ['nullable', 'integer', $this->existsInTenant('nfc_cards')],
        ];
    }
}

class TestBoundHistoryRequest extends TenantOwnedResourceRequest
{
    protected function resourceModelClass(): string
    {
        return QueueStatusHistory::class;
    }

    protected function boundParentChildRelations(): array
    {
        return [['parent' => 'queue', 'relation' => 'statusHistories', 'child' => 'history']];
    }
}

class TestAuditLogRequest extends TenantOwnedResourceRequest
{
    protected function resourceModelClass(): string
    {
        return AuditLog::class;
    }
}

class TestQueueHistoryCreateRequest extends TenantOwnedResourceRequest
{
    protected function resourceModelClass(): string
    {
        return QueueStatusHistory::class;
    }
}
