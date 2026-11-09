<?php

namespace Tests\Feature;

use App\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class MySqlQueueSchemaIntegrationTest extends TestCase
{
    private string $connectionName = 'tapzy_mysql_queues_integration';

    protected function setUp(): void
    {
        parent::setUp();

        $database = getenv('TAPZY_MYSQL_TEST_DATABASE');

        if ($database !== 'tapzy_test_queues' || ! preg_match('/^tapzy_test_[A-Za-z0-9_]+$/', (string) $database)) {
            throw new RuntimeException('Queue integration tests require TAPZY_MYSQL_TEST_DATABASE=tapzy_test_queues.');
        }

        $username = getenv('TAPZY_MYSQL_TEST_USERNAME');

        if (! $username) {
            throw new RuntimeException('Set TAPZY_MYSQL_TEST_USERNAME for the isolated MySQL integration test.');
        }

        config([
            'database.connections.'.$this->connectionName => [
                'driver' => 'mysql',
                'host' => getenv('TAPZY_MYSQL_TEST_HOST') ?: '127.0.0.1',
                'port' => getenv('TAPZY_MYSQL_TEST_PORT') ?: '3306',
                'database' => $database,
                'username' => $username,
                'password' => getenv('TAPZY_MYSQL_TEST_PASSWORD') ?: '',
                'unix_socket' => getenv('TAPZY_MYSQL_TEST_SOCKET') ?: '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'prefix_indexes' => true,
                'strict' => true,
                'engine' => null,
            ],
            'database.default' => $this->connectionName,
        ]);
        DB::purge($this->connectionName);
        DB::setDefaultConnection($this->connectionName);

        $connection = DB::connection($this->connectionName);
        $actualDatabase = $connection->selectOne('SELECT DATABASE() AS database_name')->database_name;
        $version = $connection->selectOne('SELECT VERSION() AS version')->version;

        if ($actualDatabase !== 'tapzy_test_queues') {
            throw new RuntimeException('Refusing to run queue integration tests outside tapzy_test_queues.');
        }

        if (! preg_match('/^8\.4\./', $version)) {
            throw new RuntimeException('Queue integration tests require MySQL 8.4; connected version: '.$version);
        }

        foreach (['stores', 'nfc_cards', 'queue_sessions', 'queues'] as $table) {
            if (Schema::hasTable($table)) {
                throw new RuntimeException(
                    'The isolated tapzy_test_queues database must be empty before the integration test runs.'
                );
            }
        }
    }

    public function test_mysql_queue_schema_constraints_and_foreign_keys(): void
    {
        $storeMigration = require database_path('migrations/2026_11_06_000000_create_stores_table.php');
        $expiresAtMigration = require database_path('migrations/2026_11_07_000000_add_expires_at_to_stores_table.php');
        $cardsMigration = require database_path('migrations/2026_11_06_000002_create_nfc_cards_table.php');
        $sessionsMigration = require database_path('migrations/2026_11_06_000003_create_queue_sessions_table.php');
        $queuesMigration = require database_path('migrations/2026_11_06_000004_create_queues_table.php');

        try {
            $storeMigration->up();
            $expiresAtMigration->up();
            $cardsMigration->up();
            $sessionsMigration->up();
            $queuesMigration->up();

            $expectedColumns = [
                'id', 'store_id', 'queue_session_id', 'nfc_card_id', 'queue_number', 'formatted_number',
                'customer_name', 'customer_phone', 'source', 'access_token_hash', 'status', 'called_at',
                'served_at', 'completed_at', 'cancelled_at', 'notes', 'created_at', 'updated_at',
            ];
            $this->assertSame($expectedColumns, Schema::getColumnListing('queues'));

            $this->assertColumn('store_id', 'bigint unsigned', 'NO');
            $this->assertColumn('queue_session_id', 'bigint unsigned', 'NO');
            $this->assertColumn('nfc_card_id', 'bigint unsigned', 'YES');
            $this->assertColumn('queue_number', 'int unsigned', 'NO');
            $this->assertColumn('formatted_number', 'varchar(20)', 'NO');
            $this->assertColumn('source', "enum('nfc','barcode','manual')", 'NO');
            $this->assertColumn('access_token_hash', 'char(64)', 'NO');
            $this->assertColumn(
                'status',
                "enum('waiting','called','in_service','skipped','completed','cancelled')",
                'NO'
            );
            $this->assertFalse(Schema::hasColumn('queues', 'deleted_at'));

            $this->assertIndex('queues_store_id_index', ['store_id']);
            $this->assertIndex('queues_queue_session_id_index', ['queue_session_id']);
            $this->assertIndex('queues_nfc_card_id_index', ['nfc_card_id']);
            $this->assertIndex('queues_source_index', ['source']);
            $this->assertIndex('queues_status_index', ['status']);
            $this->assertIndex('uq_session_queue_num', ['queue_session_id', 'queue_number'], true);
            $this->assertIndex('uq_queue_access_token_hash', ['access_token_hash'], true);
            $this->assertIndex('idx_store_queue_status_time', ['store_id', 'status', 'created_at']);

            $this->assertForeignKey('store_id', 'stores', 'restrict');
            $this->assertForeignKey('queue_session_id', 'queue_sessions', 'restrict');
            $this->assertForeignKey('nfc_card_id', 'nfc_cards', 'set null');

            $store = $this->createStore('Queue test store');
            $otherStore = $this->createStore('Other queue test store');
            $session = $store->queueSessions()->create([
                'session_code' => 'QUEUE-SESSION-1',
                'session_date' => '2026-11-06',
                'status' => 'active',
                'opened_at' => now(),
            ]);
            $otherStoreSession = $otherStore->queueSessions()->create([
                'session_code' => 'QUEUE-SESSION-1',
                'session_date' => '2026-11-06',
                'status' => 'active',
                'opened_at' => now(),
            ]);
            $card = $store->nfcCards()->create([
                'card_uid' => 'queue-card-'.bin2hex(random_bytes(5)),
                'public_token_hash' => hash('sha256', random_bytes(32)),
                'label' => 'Queue test card',
            ]);
            $otherStoreCard = $otherStore->nfcCards()->create([
                'card_uid' => 'queue-card-'.bin2hex(random_bytes(5)),
                'public_token_hash' => hash('sha256', random_bytes(32)),
                'label' => 'Other store queue test card',
            ]);

            $queueId = $this->insertQueue(
                $store->id,
                $session->id,
                $card->id,
                1,
                hash('sha256', 'queue-token-1'),
                'nfc'
            );

            try {
                $this->insertQueue($store->id, $session->id, null, 1, hash('sha256', 'queue-token-2'));
                $this->fail('Duplicate queue numbers in one session must be rejected.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }

            $this->insertQueue(
                $otherStore->id,
                $otherStoreSession->id,
                null,
                1,
                hash('sha256', 'queue-token-2'),
                'barcode'
            );
            $this->insertQueue(
                $otherStore->id,
                $otherStoreSession->id,
                $otherStoreCard->id,
                2,
                hash('sha256', 'barcode-card-queue-token'),
                'barcode'
            );

            $this->assertDatabaseHas('queues', [
                'id' => $queueId,
                'source' => 'nfc',
                'nfc_card_id' => $card->id,
            ]);

            try {
                $this->insertQueue($store->id, $session->id, null, 2, hash('sha256', 'queue-token-1'));
                $this->fail('Duplicate access token hashes must be rejected.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }

            try {
                $this->insertQueue(999999, $session->id, null, 3, hash('sha256', 'queue-token-4'));
                $this->fail('A missing store foreign key must be rejected.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }

            try {
                $this->insertQueue($store->id, 999999, null, 3, hash('sha256', 'queue-token-5'));
                $this->fail('A missing queue session foreign key must be rejected.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }

            try {
                $this->insertQueue($store->id, $session->id, 999999, 3, hash('sha256', 'queue-token-6'), 'nfc');
                $this->fail('A missing NFC card foreign key must be rejected.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }

            try {
                DB::table('queue_sessions')->where('id', $session->id)->delete();
                $this->fail('A queue session with queues must be protected by ON DELETE RESTRICT.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }

            try {
                DB::table('stores')->where('id', $store->id)->delete();
                $this->fail('A store with queues must be protected by ON DELETE RESTRICT.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        } finally {
            if (Schema::hasTable('queues')) {
                $queuesMigration->down();
            }

            if (Schema::hasTable('queue_sessions')) {
                $sessionsMigration->down();
            }

            if (Schema::hasTable('nfc_cards')) {
                $cardsMigration->down();
            }

            if (Schema::hasTable('stores')) {
                $expiresAtMigration->down();
                $storeMigration->down();
            }
        }
    }

    public function test_hard_delete_card_preserves_nfc_queue_as_history(): void
    {
        $storeMigration = require database_path('migrations/2026_11_06_000000_create_stores_table.php');
        $expiresAtMigration = require database_path('migrations/2026_11_07_000000_add_expires_at_to_stores_table.php');
        $cardsMigration = require database_path('migrations/2026_11_06_000002_create_nfc_cards_table.php');
        $sessionsMigration = require database_path('migrations/2026_11_06_000003_create_queue_sessions_table.php');
        $queuesMigration = require database_path('migrations/2026_11_06_000004_create_queues_table.php');

        try {
            $storeMigration->up();
            $expiresAtMigration->up();
            $cardsMigration->up();
            $sessionsMigration->up();
            $queuesMigration->up();

            $store = $this->createStore('NFC history store');
            $session = $store->queueSessions()->create([
                'session_code' => 'NFC-HISTORY-'.bin2hex(random_bytes(4)),
                'session_date' => '2026-11-06',
                'opened_at' => now(),
            ]);
            $card = $store->nfcCards()->create([
                'card_uid' => 'nfc-history-'.bin2hex(random_bytes(4)),
                'public_token_hash' => hash('sha256', random_bytes(32)),
                'label' => 'Historical NFC card',
            ]);
            $queueId = $this->insertQueue(
                $store->id,
                $session->id,
                $card->id,
                1,
                hash('sha256', 'nfc-history-queue-token'),
                'nfc'
            );

            $this->assertDatabaseHas('queues', [
                'id' => $queueId,
                'source' => 'nfc',
                'nfc_card_id' => $card->id,
            ]);

            DB::table('nfc_cards')->where('id', $card->id)->delete();

            $queueAfterCardDeletion = DB::table('queues')->where('id', $queueId)->first();
            $this->assertNotNull($queueAfterCardDeletion);
            $this->assertNull($queueAfterCardDeletion->nfc_card_id);
            $this->assertSame('nfc', $queueAfterCardDeletion->source);
        } finally {
            if (Schema::hasTable('queues')) {
                $queuesMigration->down();
            }

            if (Schema::hasTable('queue_sessions')) {
                $sessionsMigration->down();
            }

            if (Schema::hasTable('nfc_cards')) {
                $cardsMigration->down();
            }

            if (Schema::hasTable('stores')) {
                $expiresAtMigration->down();
                $storeMigration->down();
            }
        }
    }

    private function assertColumn(string $column, string $type, string $nullable): void
    {
        $actual = DB::selectOne(
            'SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['queues', $column]
        );

        $this->assertNotNull($actual);
        $this->assertSame($type, strtolower($actual->COLUMN_TYPE));
        $this->assertSame($nullable, $actual->IS_NULLABLE);
    }

    /** @param list<string> $columns */
    private function assertIndex(string $name, array $columns, bool $unique = false): void
    {
        $index = collect(Schema::getIndexes('queues'))->firstWhere('name', $name);

        $this->assertNotNull($index, "Expected index {$name} to exist.");
        $this->assertSame($columns, $index['columns']);
        $this->assertSame($unique, $index['unique']);
    }

    private function assertForeignKey(string $column, string $parentTable, string $onDelete): void
    {
        $foreignKey = collect(Schema::getForeignKeys('queues'))
            ->first(fn (array $key) => $key['columns'] === [$column]);

        $this->assertNotNull($foreignKey);
        $this->assertSame($parentTable, $foreignKey['foreign_table']);
        $this->assertSame(['id'], $foreignKey['foreign_columns']);
        $this->assertSame($onDelete, $foreignKey['on_delete']);
        $this->assertSame('cascade', $foreignKey['on_update']);
    }

    private function createStore(string $name): Store
    {
        return Store::create([
            'name' => $name,
            'slug' => 'queue-test-'.bin2hex(random_bytes(6)),
            'expires_at' => now()->addYear(),
        ]);
    }

    private function insertQueue(
        int $storeId,
        int $sessionId,
        ?int $cardId,
        int $queueNumber,
        string $tokenHash,
        string $source = 'manual'
    ): int {
        return DB::table('queues')->insertGetId([
            'store_id' => $storeId,
            'queue_session_id' => $sessionId,
            'nfc_card_id' => $cardId,
            'queue_number' => $queueNumber,
            'formatted_number' => 'A-'.str_pad((string) $queueNumber, 3, '0', STR_PAD_LEFT),
            'source' => $source,
            'access_token_hash' => $tokenHash,
            'status' => 'waiting',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
