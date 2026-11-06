<?php

namespace Tests\Feature;

use App\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class MySqlNfcQueueSchemaIntegrationTest extends TestCase
{
    private string $connectionName = 'tapzy_mysql_integration';

    private bool $createdStoresTable = false;

    /** @var list<int> */
    private array $testStoreIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $database = getenv('TAPZY_MYSQL_TEST_DATABASE');

        if (! $database) {
            $this->markTestSkipped('Set TAPZY_MYSQL_TEST_DATABASE to run isolated MySQL 8.4 integration tests.');
        }

        if (! preg_match('/^tapzy_test_[A-Za-z0-9_]+$/', $database)) {
            throw new RuntimeException('MySQL integration tests require a database named tapzy_test_*.');
        }

        config([
            'database.connections.'.$this->connectionName => [
                'driver' => 'mysql',
                'host' => getenv('TAPZY_MYSQL_TEST_HOST') ?: '127.0.0.1',
                'port' => getenv('TAPZY_MYSQL_TEST_PORT') ?: '3306',
                'database' => $database,
                'username' => getenv('TAPZY_MYSQL_TEST_USERNAME') ?: 'root',
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

        $version = DB::selectOne('SELECT VERSION() AS version')->version;

        if (! preg_match('/^8\.4\./', $version)) {
            throw new RuntimeException('MySQL integration tests require MySQL 8.4; connected version: '.$version);
        }

        if (Schema::hasTable('nfc_cards') || Schema::hasTable('queue_sessions')) {
            $this->markTestSkipped('The dedicated MySQL test database must not already contain these subtables.');
        }

        if (! Schema::hasTable('stores')) {
            Schema::create('stores', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->string('slug', 150)->unique();
                $table->timestamps();
                $table->softDeletes();
            });
            $this->createdStoresTable = true;
        }
    }

    public function test_mysql_migrations_enforce_generated_column_unique_keys_and_foreign_keys(): void
    {
        $nfcMigration = require database_path('migrations/2026_11_06_000002_create_nfc_cards_table.php');
        $sessionsMigration = require database_path('migrations/2026_11_06_000003_create_queue_sessions_table.php');

        try {
            $nfcMigration->up();
            $sessionsMigration->up();

            $this->assertTrue(Schema::hasIndex('nfc_cards', 'uq_store_card_uid', 'unique'));
            $this->assertTrue(Schema::hasIndex('nfc_cards', 'idx_store_card_status'));
            $this->assertTrue(Schema::hasIndex('queue_sessions', 'uq_store_session_code', 'unique'));
            $this->assertTrue(Schema::hasIndex('queue_sessions', 'uq_store_single_active_session', 'unique'));

            $generatedColumn = DB::selectOne(
                'SELECT EXTRA, GENERATION_EXPRESSION FROM information_schema.COLUMNS '
                .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['queue_sessions', 'active_store_id']
            );
            $this->assertNotNull($generatedColumn);
            $this->assertStringContainsString('VIRTUAL GENERATED', strtoupper($generatedColumn->EXTRA));
            $this->assertStringContainsString('status', strtolower($generatedColumn->GENERATION_EXPRESSION));
            $this->assertStringContainsString('store_id', strtolower($generatedColumn->GENERATION_EXPRESSION));

            $this->assertStoreForeignKey('nfc_cards');
            $this->assertStoreForeignKey('queue_sessions');

            $store = Store::create([
                'name' => 'Integration Store',
                'slug' => 'integration-'.bin2hex(random_bytes(6)),
            ]);
            $this->testStoreIds[] = $store->id;

            $cardId = DB::table('nfc_cards')->insertGetId([
                'store_id' => $store->id,
                'card_uid' => 'uid-'.bin2hex(random_bytes(5)),
                'public_token_hash' => hash('sha256', random_bytes(32)),
                'label' => 'Integration card',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('nfc_cards')->where('id', $cardId)->update(['deleted_at' => now()]);

            try {
                DB::table('nfc_cards')->insert([
                    'store_id' => $store->id,
                    'card_uid' => DB::table('nfc_cards')->where('id', $cardId)->value('card_uid'),
                    'public_token_hash' => hash('sha256', random_bytes(32)),
                    'label' => 'Reused UID',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->fail('A soft-deleted card UID must remain reserved for its store.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }

            $sessionDate = '2026-11-06';
            $this->insertSession($store->id, 'SESSION-ACTIVE-1', $sessionDate, 'active');

            try {
                $this->insertSession($store->id, 'SESSION-ACTIVE-2', $sessionDate, 'active');
                $this->fail('A store cannot have two active queue sessions.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }

            $otherStore = Store::create([
                'name' => 'Other Integration Store',
                'slug' => 'integration-'.bin2hex(random_bytes(6)),
            ]);
            $this->testStoreIds[] = $otherStore->id;
            $this->insertSession($otherStore->id, 'SESSION-ACTIVE-1', $sessionDate, 'active');

            DB::table('queue_sessions')->where('store_id', $store->id)->update([
                'status' => 'closed',
                'closed_at' => now(),
            ]);
            $this->insertSession($store->id, 'SESSION-CLOSED-1', $sessionDate, 'closed');
            $this->insertSession($store->id, 'SESSION-CLOSED-2', $sessionDate, 'closed');
            $this->insertSession($store->id, 'SESSION-ACTIVE-3', $sessionDate, 'active');

            try {
                $this->insertSession($store->id, 'SESSION-CLOSED-1', '2026-11-07', 'closed');
                $this->fail('A session code cannot be reused within the same store.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }

            try {
                DB::table('stores')->where('id', $store->id)->delete();
                $this->fail('A store with cards or sessions must be protected by ON DELETE RESTRICT.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        } finally {
            if (Schema::hasTable('queue_sessions')) {
                $sessionsMigration->down();
            }

            if (Schema::hasTable('nfc_cards')) {
                $nfcMigration->down();
            }

            if ($this->testStoreIds !== [] && Schema::hasTable('stores')) {
                DB::table('stores')->whereIn('id', $this->testStoreIds)->delete();
            }

            if ($this->createdStoresTable) {
                Schema::dropIfExists('stores');
                $this->createdStoresTable = false;
            }
        }
    }

    private function assertStoreForeignKey(string $table): void
    {
        $foreignKey = collect(Schema::getForeignKeys($table))
            ->first(fn (array $foreignKey) => $foreignKey['columns'] === ['store_id']);

        $this->assertNotNull($foreignKey);
        $this->assertSame('stores', $foreignKey['foreign_table']);
        $this->assertSame(['id'], $foreignKey['foreign_columns']);
        $this->assertSame('restrict', $foreignKey['on_delete']);
        $this->assertSame('cascade', $foreignKey['on_update']);
    }

    private function insertSession(int $storeId, string $code, string $date, string $status): void
    {
        DB::table('queue_sessions')->insert([
            'store_id' => $storeId,
            'session_code' => $code,
            'session_date' => $date,
            'status' => $status,
            'opened_at' => now(),
            'closed_at' => $status === 'closed' ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
