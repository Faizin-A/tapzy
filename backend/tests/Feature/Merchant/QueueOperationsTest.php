<?php

namespace Tests\Feature\Merchant;

use App\Models\Store;
use App\Models\User;
use App\Services\Merchant\QueueManagementService;
use App\Support\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\InteractsWithPrefixedMySqlDatabase;
use Tests\TestCase;
use Throwable;

class QueueOperationsTest extends TestCase
{
    use InteractsWithPrefixedMySqlDatabase;

    private array $migrations = [];

    private const TABLES = [
        'queue_status_histories', 'orders', 'queues', 'queue_sessions', 'nfc_cards',
        'audit_logs', 'users', 'stores', 'products', 'sessions', 'password_reset_tokens',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=', 'session.driver' => 'array']);
        $this->setUpPrefixedMySqlDatabase('qop');

        foreach ([
            '0001_01_01_000000_create_users_table.php',
            '2026_11_06_000000_create_stores_table.php',
        ] as $path) {
            $migration = require database_path('migrations/'.$path);
            $migration->up();
            $this->migrations[] = $migration;
        }

        $this->addTenantColumnsWithPrefixedForeignKey();

        foreach ([
            '2026_11_06_000002_create_nfc_cards_table.php',
            '2026_11_06_000003_create_queue_sessions_table.php',
            '2026_11_06_000004_create_queues_table.php',
            '2026_11_06_000006_create_orders_table.php',
            '2026_11_06_000008_create_queue_status_histories_table.php',
            '2026_11_06_000009_create_audit_logs_table.php',
            '2026_11_06_000010_add_session_generation_to_users_table.php',
            '2026_11_07_000000_add_expires_at_to_stores_table.php',
        ] as $path) {
            $migration = require database_path('migrations/'.$path);
            $migration->up();
            $this->migrations[] = $migration;
        }
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->migrations) as $migration) {
            try {
                $migration->down();
            } catch (Throwable) {
                // The prefixed connection is discarded below.
            }
        }
        $this->tearDownPrefixedMySqlDatabase(self::TABLES);
        parent::tearDown();
    }

    public function test_merchant_can_list_and_transition_queue_with_order_completion(): void
    {
        [$store, $merchant] = $this->merchantFixture('operations');
        $sessionId = $this->insertSession($store->id);
        $queueId = $this->insertQueue($store->id, $sessionId, 1, 'waiting');
        DB::table('orders')->insert([
            'order_code' => 'ORD-OPERATIONS',
            'store_id' => $store->id,
            'queue_id' => $queueId,
            'total_items' => 1,
            'total_price' => 25000,
            'status' => 'ready',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->merchantRequest($merchant)->getJson('/api/v1/merchant/queues')
            ->assertOk()
            ->assertJsonPath('data.0.id', $queueId)
            ->assertJsonPath('data.0.status', 'waiting')
            ->assertJsonPath('data.0.has_order', true);

        $this->merchantRequest($merchant)->postJson('/api/v1/merchant/queues/next/call')
            ->assertOk()->assertJsonPath('data.status', 'called');
        $this->merchantRequest($merchant)->postJson("/api/v1/merchant/queues/{$queueId}/start")
            ->assertOk()->assertJsonPath('data.status', 'in_service');
        $this->merchantRequest($merchant)->postJson("/api/v1/merchant/queues/{$queueId}/complete")
            ->assertOk()->assertJsonPath('data.status', 'completed');

        $this->assertSame('completed', DB::table('orders')->where('queue_id', $queueId)->value('status'));
        $this->assertSame(3, DB::table('queue_status_histories')->where('queue_id', $queueId)->count());
    }

    public function test_queue_operations_are_tenant_scoped_and_invalid_transitions_are_rejected(): void
    {
        [$storeA, $merchantA] = $this->merchantFixture('tenant-a');
        [$storeB, $merchantB] = $this->merchantFixture('tenant-b');
        $sessionB = $this->insertSession($storeB->id);
        $queueB = $this->insertQueue($storeB->id, $sessionB, 1, 'waiting');

        $this->merchantRequest($merchantA)->getJson('/api/v1/merchant/queues')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->merchantRequest($merchantA)->getJson("/api/v1/merchant/queues/{$queueB}")
            ->assertNotFound();
        $this->merchantRequest($merchantA)->postJson("/api/v1/merchant/queues/{$queueB}/cancel")
            ->assertNotFound();

        $sessionA = $this->insertSession($storeA->id);
        $queueA = $this->insertQueue($storeA->id, $sessionA, 1, 'waiting');
        $this->merchantRequest($merchantA)->postJson("/api/v1/merchant/queues/{$queueA}/complete")
            ->assertConflict();
        $this->merchantRequest($merchantA)->postJson("/api/v1/merchant/queues/{$queueA}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(0, DB::table('queue_status_histories')->where('queue_id', $queueB)->count());
    }

    public function test_only_one_queue_can_be_in_service_and_cancelled_order_rolls_back_completion(): void
    {
        [$store, $merchant] = $this->merchantFixture('single-service');
        $sessionId = $this->insertSession($store->id);
        $first = $this->insertQueue($store->id, $sessionId, 1, 'called');
        $second = $this->insertQueue($store->id, $sessionId, 2, 'called');

        $this->merchantRequest($merchant)->postJson("/api/v1/merchant/queues/{$first}/start")->assertOk();
        $this->merchantRequest($merchant)->postJson("/api/v1/merchant/queues/{$second}/start")->assertConflict();

        DB::table('orders')->insert([
            'order_code' => 'ORD-CANCELLED',
            'store_id' => $store->id,
            'queue_id' => $first,
            'status' => 'cancelled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $beforeHistory = DB::table('queue_status_histories')->where('queue_id', $first)->count();
        $this->merchantRequest($merchant)->postJson("/api/v1/merchant/queues/{$first}/complete")->assertConflict();
        $this->assertSame('in_service', DB::table('queues')->where('id', $first)->value('status'));
        $this->assertSame($beforeHistory, DB::table('queue_status_histories')->where('queue_id', $first)->count());
    }

    public function test_concurrent_next_call_assigns_distinct_queues(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair')) {
            $this->markTestSkipped('Process and socket support is required for a real concurrency check.');
        }

        [$store, $merchant] = $this->merchantFixture('concurrency');
        $sessionId = $this->insertSession($store->id);
        $this->insertQueue($store->id, $sessionId, 1, 'waiting');
        $this->insertQueue($store->id, $sessionId, 2, 'waiting');
        $connectionName = (string) config('database.default');
        $channels = [];
        $children = [];

        try {
            for ($index = 0; $index < 2; $index++) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                $pid = pcntl_fork();
                if ($pid === 0) {
                    fclose($pair[0]);
                    $this->runNextCallWorker($pair[1], $connectionName, $store->id, $merchant->id);
                    exit(0);
                }
                fclose($pair[1]);
                $children[$pid] = true;
                $channels[$pid] = $pair[0];
            }

            foreach ($channels as $channel) {
                fwrite($channel, "go\n");
            }
            $queueIds = [];
            foreach ($channels as $pid => $channel) {
                stream_set_timeout($channel, 15);
                $line = fgets($channel);
                $this->assertIsString($line, "Concurrency worker {$pid} did not return.");
                $result = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $this->assertArrayNotHasKey('error', $result, $result['message'] ?? 'Worker failed.');
                $queueIds[] = $result['queue_id'];
            }
            $this->assertCount(2, array_unique($queueIds));
            $this->assertSame(2, DB::table('queues')->whereIn('id', $queueIds)->where('status', 'called')->count());
            $this->assertSame(2, DB::table('queue_status_histories')->whereIn('queue_id', $queueIds)->count());
        } finally {
            foreach ($channels as $channel) {
                if (is_resource($channel)) fclose($channel);
            }
            foreach (array_keys($children) as $pid) {
                pcntl_waitpid($pid, $status);
            }
        }
    }

    private function runNextCallWorker($channel, string $connectionName, int $storeId, int $merchantId): void
    {
        if (fgets($channel) !== "go\n") {
            fwrite($channel, json_encode(['error' => 'Concurrency barrier failed'])."\n");
            exit(1);
        }

        try {
            DB::purge($connectionName);
            DB::setDefaultConnection($connectionName);
            $store = Store::withoutGlobalScopes()->findOrFail($storeId);
            $merchant = User::findOrFail($merchantId);
            app(TenantContext::class)->setMerchantStore($merchant, $store);
            $queue = app(QueueManagementService::class)->callNext($store);
            fwrite($channel, json_encode(['queue_id' => $queue->id], JSON_THROW_ON_ERROR)."\n");
            fclose($channel);
            exit(0);
        } catch (Throwable $exception) {
            fwrite($channel, json_encode(['error' => get_class($exception), 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR)."\n");
            fclose($channel);
            exit(1);
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
                    $table->foreign('store_id', $this->foreignKeyName)->references('id')->on('stores')->restrictOnDelete()->cascadeOnUpdate();
                });
            }

            public function down(): void
            {
                if (! Schema::hasTable('users')) return;
                if (Schema::hasColumn('users', 'store_id')) Schema::table('users', fn (Blueprint $table) => $table->dropForeign($this->foreignKeyName));
                Schema::table('users', function (Blueprint $table): void {
                    foreach (['role', 'store_id', 'deleted_at'] as $column) if (Schema::hasColumn('users', $column)) $table->dropColumn($column);
                });
            }
        };
        $migration->up();
        $this->migrations[] = $migration;
    }

    private function merchantRequest(User $merchant): static
    {
        Auth::forgetGuards();
        return $this->withHeaders(['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'])
            ->withSession(['auth_session_generation' => 1])
            ->actingAs($merchant, 'web');
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

    private function insertQueue(int $storeId, int $sessionId, int $number, string $status): int
    {
        return DB::table('queues')->insertGetId(['store_id' => $storeId, 'queue_session_id' => $sessionId, 'queue_number' => $number, 'formatted_number' => 'A-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT), 'source' => 'manual', 'access_token_hash' => hash('sha256', Str::random(40)), 'status' => $status, 'called_at' => in_array($status, ['called', 'in_service'], true) ? now() : null, 'served_at' => $status === 'in_service' ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
    }
}
