<?php

namespace Tests\Feature\Merchant;

use App\Models\Store;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithPrefixedMySqlDatabase;
use Tests\TestCase;

class QueueSessionLifecycleTest extends TestCase
{
    use InteractsWithPrefixedMySqlDatabase;

    private array $migrations = [];

    private const TABLES = ['queue_status_histories', 'orders', 'queues', 'queue_sessions', 'nfc_cards', 'audit_logs', 'users', 'stores'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=', 'session.driver' => 'array']);
        // Keep the table prefix short enough for Laravel's automatic MySQL
        // index/foreign-key names (MySQL identifier limit: 64 chars).
        $this->setUpPrefixedMySqlDatabase('qsl');

        $paths = [
            '0001_01_01_000000_create_users_table.php',
            '2026_11_06_000000_create_stores_table.php',
            '2026_11_06_000002_create_nfc_cards_table.php',
            '2026_11_06_000003_create_queue_sessions_table.php',
            '2026_11_06_000004_create_queues_table.php',
            '2026_11_06_000006_create_orders_table.php',
            '2026_11_06_000008_create_queue_status_histories_table.php',
            '2026_11_06_000009_create_audit_logs_table.php',
            '2026_11_06_000010_add_session_generation_to_users_table.php',
            '2026_11_07_000000_add_expires_at_to_stores_table.php',
            '2026_11_09_000000_add_idempotency_to_queues_table.php',
            '2026_11_13_000000_add_access_token_ciphertext_to_queues_table.php',
        ];

        foreach (array_slice($paths, 0, 2) as $path) {
            $migration = require database_path('migrations/'.$path);
            $migration->up();
            $this->migrations[] = $migration;
        }
        $this->addTenantColumnsWithPrefixedForeignKey();
        foreach (array_slice($paths, 2) as $path) {
            $migration = require database_path('migrations/'.$path);
            $migration->up();
            $this->migrations[] = $migration;
        }
    }

    private function addTenantColumnsWithPrefixedForeignKey(): void
    {
        $foreignKeyName = DB::getTablePrefix().'users_store_id_foreign';
        $migration = new class($foreignKeyName) extends Migration
        {
            public function __construct(private readonly string $foreignKeyName) {}

            public function up(): void
            {
                Schema::table('users', function (Blueprint $table): void {
                    $table->string('name', 150)->change();
                    $table->enum('role', ['super_admin', 'merchant'])->default('merchant');
                    $table->unsignedBigInteger('store_id')->nullable();
                    $table->softDeletes();
                    $table->index('role');
                    $table->index('store_id');
                    $table->index('deleted_at');
                });
                Schema::table('users', function (Blueprint $table): void {
                    $table->foreign('store_id', $this->foreignKeyName)
                        ->references('id')->on('stores')->restrictOnDelete()->cascadeOnUpdate();
                });
            }

            public function down(): void
            {
                if (! Schema::hasTable('users')) return;

                if (Schema::hasColumn('users', 'store_id')) {
                    Schema::table('users', fn (Blueprint $table) => $table->dropForeign($this->foreignKeyName));
                }
                Schema::table('users', function (Blueprint $table): void {
                    foreach (['role', 'store_id', 'deleted_at'] as $column) {
                        if (Schema::hasColumn('users', $column)) $table->dropColumn($column);
                    }
                });
            }
        };

        $migration->up();
        $this->migrations[] = $migration;
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->migrations) as $migration) {
            try {
                $migration->down();
            } catch (\Throwable) {
                // The prefixed test connection is discarded immediately below.
            }
        }
        $this->tearDownPrefixedMySqlDatabase(self::TABLES);
        parent::tearDown();
    }

    public function test_merchant_can_open_close_and_reset_session_with_business_rules(): void
    {
        [$store, $merchant] = $this->merchantFixture('lifecycle');
        $request = $this->merchantRequest($merchant);

        $request->getJson('/api/v1/merchant/queue-session')->assertOk()->assertJsonPath('data', null);
        $opened = $request->postJson('/api/v1/merchant/queue-session')->assertOk()->assertJsonPath('data.status', 'active');
        $oldSessionId = DB::table('queue_sessions')->where('store_id', $store->id)->where('status', 'active')->value('id');
        $request->postJson('/api/v1/merchant/queue-session')->assertConflict();

        $statuses = ['waiting', 'called', 'in_service', 'skipped', 'completed', 'cancelled'];
        $queueIds = [];
        foreach ($statuses as $number => $status) {
            $queueIds[$status] = DB::table('queues')->insertGetId([
                'store_id' => $store->id, 'queue_session_id' => $oldSessionId, 'queue_number' => $number + 1,
                'formatted_number' => 'A-'.str_pad((string) ($number + 1), 3, '0', STR_PAD_LEFT),
                'source' => 'manual', 'access_token_hash' => hash('sha256', $status), 'status' => $status,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (['pending', 'confirmed', 'preparing', 'ready', 'completed'] as $number => $status) {
            DB::table('orders')->insert(['order_code' => 'ORD-'.$status, 'store_id' => $store->id, 'queue_id' => $queueIds[$statuses[$number]], 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        }

        $reset = $request->postJson('/api/v1/merchant/queue-session/reset')->assertOk()->assertJsonPath('data.status', 'active');
        $newSessionId = DB::table('queue_sessions')->where('store_id', $store->id)->where('status', 'active')->value('id');
        $this->assertNotSame((int) $oldSessionId, (int) $newSessionId);
        $this->assertSame('closed', DB::table('queue_sessions')->where('id', $oldSessionId)->value('status'));
        $this->assertSame(5, DB::table('queues')->whereIn('id', array_values($queueIds))->where('status', 'cancelled')->count());
        $this->assertSame(1, DB::table('queues')->where('id', $queueIds['completed'])->where('status', 'completed')->count());
        $this->assertSame(4, DB::table('queue_status_histories')->whereIn('queue_id', array_values($queueIds))->count());
        $this->assertSame(4, DB::table('orders')->whereIn('queue_id', array_values($queueIds))->where('status', 'cancelled')->count());
        $this->assertSame(1, DB::table('orders')->where('queue_id', $queueIds['completed'])->where('status', 'completed')->count());
        $this->assertSame(4, DB::table('queue_status_histories')->where('notes', 'Auto-cancelled due to session reset')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', AuditLogService::QUEUE_SESSION_OPENED)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', AuditLogService::QUEUE_SESSION_RESET)->count());

        $request->patchJson('/api/v1/merchant/queue-session/close')->assertOk()->assertJsonPath('data.status', 'closed');
        $this->assertSame(1, DB::table('audit_logs')->where('action', AuditLogService::QUEUE_SESSION_CLOSED)->count());
    }

    public function test_session_endpoint_is_tenant_scoped_and_store_id_is_rejected(): void
    {
        [$storeA, $merchantA] = $this->merchantFixture('store-a');
        [$storeB, $merchantB] = $this->merchantFixture('store-b');
        $this->merchantRequest($merchantB)->postJson('/api/v1/merchant/queue-session')->assertOk();
        $this->merchantRequest($merchantA)->postJson('/api/v1/merchant/queue-session', ['store_id' => $storeB->id])->assertUnprocessable()->assertJsonValidationErrors('store_id');
        $this->merchantRequest($merchantA)->getJson('/api/v1/merchant/queue-session')->assertOk()->assertJsonPath('data', null);
        $this->merchantRequest($merchantA)->patchJson('/api/v1/merchant/queue-session/close')->assertConflict();
        $this->merchantRequest($merchantA)->postJson('/api/v1/merchant/queue-session/reset')->assertConflict();
        $this->assertSame(1, DB::table('queue_sessions')->where('store_id', $storeB->id)->count());
        $this->assertSame(0, DB::table('queue_sessions')->where('store_id', $storeA->id)->count());
    }

    public function test_inactive_store_cannot_manage_queue_session(): void
    {
        [$store, $merchant] = $this->merchantFixture('inactive');
        DB::table('stores')->where('id', $store->id)->update(['is_active' => false]);
        $this->merchantRequest($merchant)->getJson('/api/v1/merchant/queue-session')->assertForbidden();
        $this->merchantRequest($merchant)->postJson('/api/v1/merchant/queue-session')->assertForbidden();
    }

    private function merchantRequest(User $merchant): static
    {
        // Sanctum's stateful guard can retain the previous web user between
        // sequential requests in the same test process. Reset it before
        // selecting the merchant for this request.
        Auth::forgetGuards();

        return $this->withHeaders(['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'])
            ->withSession(['auth_session_generation' => 1])
            ->actingAs($merchant, 'web');
    }

    /** @return array{0: Store, 1: User} */
    private function merchantFixture(string $suffix): array
    {
        $store = Store::create(['name' => 'Store '.$suffix, 'slug' => 'store-'.$suffix.'-'.bin2hex(random_bytes(3)), 'is_active' => true, 'expires_at' => now()->addYear()]);
        $id = DB::table('users')->insertGetId(['name' => 'Merchant', 'email' => $suffix.'-'.bin2hex(random_bytes(4)).'@example.test', 'password' => 'irrelevant', 'role' => 'merchant', 'store_id' => $store->id, 'session_generation' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return [$store, User::query()->findOrFail($id)];
    }
}
