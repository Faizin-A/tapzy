<?php

namespace Tests\Feature\Merchant;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithPrefixedMySqlDatabase;
use Tests\TestCase;
use Throwable;

class ManualQueueTest extends TestCase
{
    use InteractsWithPrefixedMySqlDatabase;

    private array $migrations = [];

    private const TABLES = [
        'order_items', 'queue_status_histories', 'orders', 'queues', 'products',
        'queue_sessions', 'nfc_cards', 'audit_logs', 'users', 'stores',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=', 'session.driver' => 'array']);
        $this->setUpPrefixedMySqlDatabase('mqueue');

        foreach (['0001_01_01_000000_create_users_table.php', '2026_11_06_000000_create_stores_table.php'] as $path) {
            $migration = require database_path('migrations/'.$path);
            $migration->up();
            $this->migrations[] = $migration;
        }
        $this->addTenantColumnsWithPrefixedForeignKey();
        foreach ([
            '2026_11_06_000002_create_nfc_cards_table.php', '2026_11_06_000003_create_queue_sessions_table.php',
            '2026_11_06_000004_create_queues_table.php', '2026_11_06_000005_create_products_table.php',
            '2026_11_06_000006_create_orders_table.php', '2026_11_06_000007_create_order_items_table.php',
            '2026_11_06_000008_create_queue_status_histories_table.php', '2026_11_06_000009_create_audit_logs_table.php',
            '2026_11_06_000010_add_session_generation_to_users_table.php', '2026_11_07_000000_add_expires_at_to_stores_table.php',
            '2026_11_09_000000_add_idempotency_to_queues_table.php', '2026_11_13_000000_add_access_token_ciphertext_to_queues_table.php',
        ] as $path) {
            $migration = require database_path('migrations/'.$path);
            $migration->up();
            $this->migrations[] = $migration;
        }
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->migrations) as $migration) {
            try { $migration->down(); } catch (Throwable) { }
        }
        $this->tearDownPrefixedMySqlDatabase(self::TABLES);
        parent::tearDown();
    }

    public function test_merchant_can_create_manual_queue_without_order(): void
    {
        [$store, $merchant] = $this->merchantFixture('manual-basic');
        $this->insertSession($store->id);

        $response = $this->merchantRequest($merchant)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/merchant/queues/manual', ['customer_name' => 'Budi', 'queue_notes' => 'Datang langsung']);

        $response->assertCreated()->assertJsonPath('data.queue_number', 1)
            ->assertJsonPath('data.formatted_number', 'A-001')->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.status', 'waiting')->assertJsonPath('data.has_order', false);
        $queueId = $response->json('data.id');
        $this->assertDatabaseHas('queues', ['id' => $queueId, 'store_id' => $store->id, 'nfc_card_id' => null, 'source' => 'manual']);
        $this->assertDatabaseHas('queue_status_histories', ['queue_id' => $queueId, 'previous_status' => null, 'new_status' => 'waiting', 'changed_by_user_id' => $merchant->id]);
    }

    public function test_manual_queue_can_create_order_with_backend_priced_items(): void
    {
        [$store, $merchant] = $this->merchantFixture('manual-order');
        $this->insertSession($store->id);
        $productId = DB::table('products')->insertGetId(['store_id' => $store->id, 'name' => 'Kopi', 'price' => 12500, 'is_active' => true, 'is_available' => true, 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->merchantRequest($merchant)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/merchant/queues/manual', ['customer_name' => 'Sari', 'items' => [['product_id' => $productId, 'quantity' => 2]]]);

        $response->assertCreated()->assertJsonPath('data.has_order', true)->assertJsonPath('data.order.status', 'pending');
        $this->assertSame('25000.00', (string) DB::table('orders')->where('queue_id', $response->json('data.id'))->value('total_price'));
    }

    public function test_manual_queue_validates_tenant_and_idempotency(): void
    {
        [$storeA, $merchantA] = $this->merchantFixture('manual-a');
        [$storeB] = $this->merchantFixture('manual-b');
        $this->insertSession($storeA->id);
        $key = (string) Str::uuid();
        $payload = ['customer_name' => 'Rina'];

        $this->merchantRequest($merchantA)->withHeader('Idempotency-Key', $key)->postJson('/api/v1/merchant/queues/manual', $payload)->assertCreated();
        $this->merchantRequest($merchantA)->withHeader('Idempotency-Key', $key)->postJson('/api/v1/merchant/queues/manual', ['customer_name' => 'Berubah'])->assertConflict();
        $this->merchantRequest($merchantA)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/merchant/queues/manual', ['customer_name' => 'X', 'store_id' => $storeB->id])->assertUnprocessable();
        $this->merchantRequest($merchantA)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/merchant/queues/manual', [])->assertUnprocessable();
    }

    public function test_manual_queue_rolls_back_when_product_is_unavailable(): void
    {
        [$store, $merchant] = $this->merchantFixture('manual-rollback');
        $this->insertSession($store->id);
        $productId = DB::table('products')->insertGetId(['store_id' => $store->id, 'name' => 'Habis', 'price' => 1000, 'is_active' => false, 'is_available' => false, 'created_at' => now(), 'updated_at' => now()]);

        $this->merchantRequest($merchant)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/merchant/queues/manual', ['customer_name' => 'Doni', 'items' => [['product_id' => $productId, 'quantity' => 1]]])->assertUnprocessable();
        $this->assertDatabaseCount('queues', 0);
        $this->assertDatabaseCount('queue_status_histories', 0);
    }

    private function merchantRequest(User $merchant): static
    {
        Auth::forgetGuards();
        return $this->withHeaders(['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'])
            ->withSession(['auth_session_generation' => 1])->actingAs($merchant, 'web');
    }

    private function merchantFixture(string $suffix): array
    {
        $store = Store::create(['name' => 'Store '.$suffix, 'slug' => 'store-'.$suffix.'-'.Str::lower(Str::random(6)), 'is_active' => true, 'expires_at' => now()->addYear()]);
        $id = DB::table('users')->insertGetId(['name' => 'Merchant', 'email' => $suffix.'-'.Str::lower(Str::random(8)).'@example.test', 'password' => 'irrelevant', 'role' => 'merchant', 'store_id' => $store->id, 'session_generation' => 1, 'created_at' => now(), 'updated_at' => now()]);
        return [$store, User::findOrFail($id)];
    }

    private function insertSession(int $storeId): int
    {
        return DB::table('queue_sessions')->insertGetId(['store_id' => $storeId, 'session_code' => 'S-'.Str::upper(Str::random(10)), 'session_date' => now()->toDateString(), 'last_queue_number' => 0, 'status' => 'active', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function addTenantColumnsWithPrefixedForeignKey(): void
    {
        $foreignKeyName = DB::getTablePrefix().'users_store_id_foreign';
        $migration = new class($foreignKeyName) extends Migration {
            public function __construct(private readonly string $foreignKeyName) {}
            public function up(): void { Schema::table('users', function (Blueprint $table): void { $table->string('name', 150)->change(); $table->enum('role', ['super_admin', 'merchant'])->default('merchant'); $table->unsignedBigInteger('store_id')->nullable(); $table->softDeletes(); $table->index('role'); $table->index('store_id'); $table->index('deleted_at'); }); Schema::table('users', fn (Blueprint $table) => $table->foreign('store_id', $this->foreignKeyName)->references('id')->on('stores')->restrictOnDelete()->cascadeOnUpdate()); }
            public function down(): void { if (! Schema::hasTable('users')) return; Schema::table('users', fn (Blueprint $table) => $table->dropForeign($this->foreignKeyName)); }
        };
        $migration->up();
        $this->migrations[] = $migration;
    }
}
