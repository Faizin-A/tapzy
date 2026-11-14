<?php

namespace Tests\Feature\Customer;

use App\Models\NfcCard;
use App\Models\QueueSession;
use App\Models\Scopes\TenantScope;
use App\Models\Store;
use App\Services\Customer\CustomerQueueCreationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithPrefixedMySqlDatabase;
use Tests\TestCase;
use Throwable;

class CustomerQueueCreationConcurrencyTest extends TestCase
{
    use InteractsWithPrefixedMySqlDatabase;

    private const ISOLATED_TABLES = [
        'queue_status_histories',
        'queues',
        'queue_sessions',
        'nfc_cards',
        'users',
        'stores',
        'sessions',
        'password_reset_tokens',
    ];

    private string $connectionName;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPrefixedMySqlDatabase('cqcon');
        $this->connectionName = (string) config('database.default');

        try {
            $this->runMigration('0001_01_01_000000_create_users_table.php');
            $this->runMigration('2026_11_06_000000_create_stores_table.php');
            $this->runMigration('2026_11_07_000000_add_expires_at_to_stores_table.php');
            $this->runMigration('2026_11_06_000002_create_nfc_cards_table.php');
            $this->runMigration('2026_11_06_000003_create_queue_sessions_table.php');
            $this->runMigration('2026_11_06_000004_create_queues_table.php');
            $this->runMigration('2026_11_09_000000_add_idempotency_to_queues_table.php');
            $this->runMigration('2026_11_06_000008_create_queue_status_histories_table.php');
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

    public function test_concurrent_same_key_creates_one_queue_and_one_replay(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid') || ! function_exists('pcntl_kill')) {
            $this->markTestSkipped('pcntl process and cleanup support is unavailable; real concurrency cannot be proven safely.');
        }

        if (! function_exists('stream_socket_pair')) {
            $this->markTestSkipped('IPC socket-pair support is unavailable; real concurrency cannot be proven safely.');
        }

        $connection = DB::connection($this->connectionName);
        $storeId = $connection->table('stores')->insertGetId([
            'name' => 'Concurrency Test Store',
            'slug' => 'concurrency-'.Str::random(8),
            'avg_service_time' => 10,
            'queue_prefix' => 'A',
            'ordering_mode' => 'queue_and_order',
            'is_active' => true,
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cardId = $connection->table('nfc_cards')->insertGetId([
            'store_id' => $storeId,
            'card_uid' => 'CONCURRENCY-'.Str::random(8),
            'public_token_hash' => hash('sha256', Str::random(40)),
            'label' => 'Concurrency card',
            'type' => 'table',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sessionId = $connection->table('queue_sessions')->insertGetId([
            'store_id' => $storeId,
            'session_code' => 'CONCURRENCY-TEST',
            'session_date' => now()->toDateString(),
            'last_queue_number' => 0,
            'status' => 'active',
            'opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $idempotencyKey = (string) Str::uuid();
        $validatedData = ['source' => 'nfc'];
        $channels = [];
        $children = [];

        try {
            for ($index = 0; $index < 2; $index++) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                $this->assertIsArray($pair);
                $pid = pcntl_fork();

                if ($pid === -1) {
                    throw new \RuntimeException('Unable to fork concurrency worker.');
                }

                if ($pid === 0) {
                    fclose($pair[0]);
                    $this->runConcurrencyWorker($pair[1], $storeId, $cardId, $idempotencyKey, $validatedData);
                    exit(0);
                }

                fclose($pair[1]);
                $children[$pid] = true;
                $channels[$pid] = $pair[0];
            }

            foreach ($channels as $channel) {
                stream_set_timeout($channel, 5);
                $this->assertSame("ready\n", fgets($channel));
            }

            foreach ($channels as $channel) {
                fwrite($channel, "go\n");
            }

            $results = [];
            foreach ($channels as $pid => $channel) {
                stream_set_timeout($channel, 15);
                $line = fgets($channel);
                $this->assertIsString($line, "Concurrency worker {$pid} did not return a result.");
                $results[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            }

            foreach ($results as $result) {
                $this->assertArrayHasKey('is_replay', $result, $result['message'] ?? $result['error'] ?? 'Concurrency worker failed.');
                $this->assertArrayHasKey('access_token', $result);
            }

            foreach (array_keys($children) as $pid) {
                $status = 0;
                $this->assertSame($pid, pcntl_waitpid($pid, $status));
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
                unset($children[$pid]);
            }

            $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['is_replay'] === false));
            $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['is_replay'] === true));
            $this->assertCount(1, array_unique(array_column($results, 'access_token')));
            $this->assertSame(1, $connection->table('queues')->where('store_id', $storeId)->count());
            $this->assertSame(1, $connection->table('queue_status_histories')->count());
            $this->assertSame(1, (int) $connection->table('queue_sessions')->where('id', $sessionId)->value('last_queue_number'));
        } finally {
            foreach ($channels as $channel) {
                if (is_resource($channel)) {
                    fclose($channel);
                }
            }

            foreach (array_keys($children) as $pid) {
                pcntl_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
        }
    }

    private function runConcurrencyWorker($channel, int $storeId, int $cardId, string $idempotencyKey, array $validatedData): void
    {
        fwrite($channel, "ready\n");
        stream_set_timeout($channel, 5);
        if (fgets($channel) !== "go\n") {
            fwrite($channel, json_encode(['error' => 'Concurrency barrier timed out.'])."\n");
            fclose($channel);
            exit(1);
        }

        try {
            DB::purge($this->connectionName);
            DB::setDefaultConnection($this->connectionName);
            $store = Store::withoutGlobalScope(TenantScope::class)->findOrFail($storeId);
            $card = NfcCard::withoutGlobalScope(TenantScope::class)->findOrFail($cardId);
            $result = app(CustomerQueueCreationService::class)->create($store, $card, $validatedData, $idempotencyKey);

            fwrite($channel, json_encode([
                'is_replay' => (bool) $result['is_replay'],
                'access_token' => $result['access_token'],
            ], JSON_THROW_ON_ERROR)."\n");
            fclose($channel);
            exit(0);
        } catch (Throwable $exception) {
            fwrite($channel, json_encode([
                'error' => get_class($exception),
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR)."\n");
            fclose($channel);
            exit(1);
        }
    }

    private function runMigration(string $filename): void
    {
        $migration = require database_path('migrations/'.$filename);
        $migration->up();
    }
}
