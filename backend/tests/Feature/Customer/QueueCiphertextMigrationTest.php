<?php

namespace Tests\Feature\Customer;

use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithPrefixedMySqlDatabase;
use Tests\TestCase;
use Throwable;

class QueueCiphertextMigrationTest extends TestCase
{
    use InteractsWithPrefixedMySqlDatabase;

    private const ISOLATED_TABLES = [
        'queues',
        'queue_sessions',
        'nfc_cards',
        'stores',
    ];

    private string $connectionName;

    private Builder $schema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPrefixedMySqlDatabase('qcm');
        $this->connectionName = (string) config('database.default');
        $this->schema = Schema::connection($this->connectionName);

        try {
            $connection = DB::connection($this->connectionName);

            $this->assertSame('mysql', $connection->getDriverName());
            $this->assertSame('tapzy_test', $connection->getDatabaseName());
            $this->assertSame('127.0.0.1', (string) $connection->getConfig('host'));
            $this->assertSame('3307', (string) $connection->getConfig('port'));
            $this->assertNotSame('', $connection->getTablePrefix());
            $this->assertSame($connection->getTablePrefix(), $this->schema->getConnection()->getTablePrefix());

            $this->runMigration('2026_11_06_000000_create_stores_table.php');
            $this->runMigration('2026_11_07_000000_add_expires_at_to_stores_table.php');
            $this->runMigration('2026_11_06_000002_create_nfc_cards_table.php');
            $this->runMigration('2026_11_06_000003_create_queue_sessions_table.php');
            $this->runMigration('2026_11_06_000004_create_queues_table.php');
            $this->runMigration('2026_11_09_000000_add_idempotency_to_queues_table.php');
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

    public function test_ciphertext_migration_is_idempotent_and_rolls_back_only_its_column(): void
    {
        $connection = DB::connection($this->connectionName);
        $migration = require database_path('migrations/2026_11_13_000000_add_access_token_ciphertext_to_queues_table.php');

        $storeId = $connection->table('stores')->insertGetId([
            'name' => 'Migration Test Store',
            'slug' => 'migration-test-'.Str::random(8),
            'avg_service_time' => 10,
            'queue_prefix' => 'A',
            'ordering_mode' => 'queue_and_order',
            'is_active' => true,
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sessionId = $connection->table('queue_sessions')->insertGetId([
            'store_id' => $storeId,
            'session_code' => 'MIGRATION-TEST',
            'session_date' => now()->toDateString(),
            'status' => 'active',
            'opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $accessTokenHash = hash('sha256', 'migration-test-token');
        $queueId = $connection->table('queues')->insertGetId([
            'store_id' => $storeId,
            'queue_session_id' => $sessionId,
            'nfc_card_id' => null,
            'queue_number' => 1,
            'formatted_number' => 'A-001',
            'source' => 'nfc',
            'access_token_hash' => $accessTokenHash,
            'status' => 'waiting',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $this->assertTrue($this->schema->hasColumn('queues', 'access_token_ciphertext'));
        $columns = $this->schema->getColumns('queues');
        $ciphertextColumn = collect($columns)->firstWhere('name', 'access_token_ciphertext');
        $hashPosition = collect($columns)->search(fn (array $column): bool => $column['name'] === 'access_token_hash');
        $ciphertextPosition = collect($columns)->search(fn (array $column): bool => $column['name'] === 'access_token_ciphertext');

        $this->assertNotNull($ciphertextColumn);
        $this->assertSame('text', strtolower((string) $ciphertextColumn['type']));
        $this->assertTrue((bool) $ciphertextColumn['nullable']);
        $this->assertSame($hashPosition + 1, $ciphertextPosition);

        // Re-running up must not alter the schema or data.
        $migration->up();
        $this->assertTrue($this->schema->hasColumn('queues', 'access_token_ciphertext'));
        $this->assertSame(1, $connection->table('queues')->count());

        $migration->down();

        $this->assertFalse($this->schema->hasColumn('queues', 'access_token_ciphertext'));
        $this->assertTrue($this->schema->hasColumn('queues', 'access_token_hash'));
        $queue = $connection->table('queues')->where('id', $queueId)->first();
        $this->assertNotNull($queue);
        $this->assertSame($accessTokenHash, $queue->access_token_hash);

        // Restore the schema so teardown sees the complete Batch 3 shape.
        $migration->up();
        $this->assertTrue($this->schema->hasColumn('queues', 'access_token_ciphertext'));
        $this->assertSame(1, $connection->table('queues')->count());
    }

    private function runMigration(string $filename): void
    {
        $migration = require database_path('migrations/'.$filename);
        $migration->up();
    }
}
