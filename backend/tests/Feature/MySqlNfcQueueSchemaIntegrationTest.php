<?php

namespace Tests\Feature;

use App\Models\Store;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class MySqlNfcQueueSchemaIntegrationTest extends TestCase
{
    private const CONNECTION_NAME = 'tapzy_mysql_nfc_queue_integration';

    private const EXPECTED_DATABASE = 'tapzy_test_nfc_queue';

    private const EXPECTED_HOST = '127.0.0.1';

    private const EXPECTED_PORT = '3307';

    private const EXPECTED_USERNAME = 'tapzy_nfc_it';

    private const EXPECTED_CURRENT_USER = 'tapzy_nfc_it@127.0.0.1';

    private const REQUIRED_VERSION_PATTERN = '/^8\.4\./';

    private const ISOLATED_TABLES = [
        'stores',
        'nfc_cards',
        'queue_sessions',
    ];

    private const CLEANUP_TABLES = [
        'queue_sessions',
        'nfc_cards',
        'stores',
    ];

    private ?string $originalDefaultConnection = null;

    private bool $databaseIsolationVerified = false;

    protected function setUp(): void
    {
        parent::setUp();

        $database = $this->requireExactEnvironmentValue('TAPZY_MYSQL_TEST_DATABASE', self::EXPECTED_DATABASE);
        $host = $this->requireExactEnvironmentValue('TAPZY_MYSQL_TEST_HOST', self::EXPECTED_HOST);
        $port = $this->requireExactEnvironmentValue('TAPZY_MYSQL_TEST_PORT', self::EXPECTED_PORT);
        $username = $this->requireExactEnvironmentValue('TAPZY_MYSQL_TEST_USERNAME', self::EXPECTED_USERNAME);
        $password = $this->requirePassword();

        config([
            'database.connections.'.self::CONNECTION_NAME => [
                'driver' => 'mysql',
                'host' => $host,
                'port' => $port,
                'database' => $database,
                'username' => $username,
                'password' => $password,
                'unix_socket' => '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'prefix_indexes' => true,
                'strict' => true,
                'engine' => null,
            ],
        ]);

        DB::purge(self::CONNECTION_NAME);

        try {
            $connection = DB::connection(self::CONNECTION_NAME);

            $this->assertConnectionIdentity($connection);
            $this->assertIsolatedTablesAreAbsent();

            $this->databaseIsolationVerified = true;

            Schema::connection(self::CONNECTION_NAME)->create('stores', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->string('slug', 150)->unique();
                $table->dateTime('expires_at');
                $table->timestamps();
                $table->softDeletes();
            });

            $this->originalDefaultConnection = DB::getDefaultConnection();
            DB::setDefaultConnection(self::CONNECTION_NAME);
        } catch (Throwable $setupException) {
            $this->cleanupAfterSetupFailure($setupException);
        }
    }

    protected function tearDown(): void
    {
        $cleanupException = null;

        try {
            $this->cleanupDatabase();
        } catch (Throwable $exception) {
            $cleanupException = $exception;
        }

        try {
            parent::tearDown();
        } catch (Throwable $tearDownException) {
            if ($cleanupException !== null) {
                throw new RuntimeException(
                    'Cleanup failed and teardown also failed. Cleanup: '
                    .$cleanupException->getMessage().' Teardown: '.$tearDownException->getMessage(),
                    0,
                    $tearDownException
                );
            }

            throw $tearDownException;
        }

        if ($cleanupException !== null) {
            throw $cleanupException;
        }
    }

    public function test_mysql_migrations_enforce_generated_column_unique_keys_and_foreign_keys(): void
    {
        $nfcMigration = require database_path('migrations/2026_11_06_000002_create_nfc_cards_table.php');
        $integrityMigration = require database_path('migrations/2026_11_08_000000_add_nfc_card_integrity_constraints.php');
        $sessionsMigration = require database_path('migrations/2026_11_06_000003_create_queue_sessions_table.php');

        $nfcMigration->up();
        $integrityMigration->up();
        $sessionsMigration->up();

        $this->assertFalse(Schema::hasIndex('nfc_cards', 'uq_store_card_uid', 'unique'));
        $this->assertTrue(Schema::hasIndex('nfc_cards', 'uq_card_uid_global', 'unique'));
        $this->assertTrue(Schema::hasIndex('nfc_cards', 'uq_store_single_card', 'unique'));
        $this->assertTrue(Schema::hasIndex('nfc_cards', 'idx_store_card_status'));
        $this->assertTrue(Schema::hasIndex('queue_sessions', 'uq_store_session_code', 'unique'));
        $this->assertTrue(Schema::hasIndex('queue_sessions', 'uq_store_single_active_session', 'unique'));

        $cardGeneratedColumn = DB::selectOne(
            'SELECT EXTRA, GENERATION_EXPRESSION FROM information_schema.COLUMNS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['nfc_cards', 'active_store_id']
        );
        $this->assertNotNull($cardGeneratedColumn);
        $this->assertStringContainsString('VIRTUAL GENERATED', strtoupper($cardGeneratedColumn->EXTRA));
        $this->assertStringContainsString('deleted_at', strtolower($cardGeneratedColumn->GENERATION_EXPRESSION));
        $this->assertStringContainsString('store_id', strtolower($cardGeneratedColumn->GENERATION_EXPRESSION));

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
            'expires_at' => now()->addYear(),
        ]);

        $firstCardUid = 'uid-'.bin2hex(random_bytes(5));
        $cardId = DB::table('nfc_cards')->insertGetId([
            'store_id' => $store->id,
            'card_uid' => $firstCardUid,
            'public_token_hash' => hash('sha256', random_bytes(32)),
            'label' => 'Integration card',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            DB::table('nfc_cards')->insert([
                'store_id' => $store->id,
                'card_uid' => 'uid-'.bin2hex(random_bytes(5)),
                'public_token_hash' => hash('sha256', random_bytes(32)),
                'label' => 'Second active card',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('A store cannot have more than one non-deleted card.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        DB::table('nfc_cards')->where('id', $cardId)->update(['deleted_at' => now()]);

        DB::table('nfc_cards')->insert([
            'store_id' => $store->id,
            'card_uid' => 'uid-'.bin2hex(random_bytes(5)),
            'public_token_hash' => hash('sha256', random_bytes(32)),
            'label' => 'Replacement card',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            DB::table('nfc_cards')->insert([
                'store_id' => $store->id,
                'card_uid' => $firstCardUid,
                'public_token_hash' => hash('sha256', random_bytes(32)),
                'label' => 'Reused UID',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('A soft-deleted card UID must remain globally reserved.');
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
            'expires_at' => now()->addYear(),
        ]);
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
    }

    private function assertConnectionIdentity(ConnectionInterface $connection): void
    {
        $database = (string) $connection->selectOne('SELECT DATABASE() AS value')->value;
        $port = (int) $connection->selectOne('SELECT @@port AS value')->value;
        $currentUser = (string) $connection->selectOne('SELECT CURRENT_USER() AS value')->value;
        $version = (string) $connection->selectOne('SELECT VERSION() AS value')->value;

        if ($database !== self::EXPECTED_DATABASE) {
            throw new RuntimeException(
                'The isolated integration connection must use database '.self::EXPECTED_DATABASE.'.'
            );
        }

        if ($port !== (int) self::EXPECTED_PORT) {
            throw new RuntimeException(
                'The isolated integration connection must use MySQL port '.self::EXPECTED_PORT.'.'
            );
        }

        if ($currentUser !== self::EXPECTED_CURRENT_USER) {
            throw new RuntimeException(
                'The isolated integration connection must authenticate as '.self::EXPECTED_CURRENT_USER.'.'
            );
        }

        if (! preg_match(self::REQUIRED_VERSION_PATTERN, $version)) {
            throw new RuntimeException(
                'MySQL integration tests require MySQL 8.4; connected version: '.$version
            );
        }
    }

    private function assertIsolatedTablesAreAbsent(): void
    {
        $schema = Schema::connection(self::CONNECTION_NAME);

        foreach (self::ISOLATED_TABLES as $table) {
            if ($schema->hasTable($table)) {
                throw new RuntimeException(
                    'The isolated database '.self::EXPECTED_DATABASE
                    .' must be empty; table '.$table.' already exists.'
                );
            }
        }
    }

    private function requireExactEnvironmentValue(string $name, string $expected): string
    {
        $value = getenv($name);

        if ($value === false || $value !== $expected) {
            throw new RuntimeException(
                $name.' must be exactly '.$expected.' for the isolated MySQL NFC/queue integration test.'
            );
        }

        return $value;
    }

    private function requirePassword(): string
    {
        $password = getenv('TAPZY_MYSQL_TEST_PASSWORD');

        if ($password === false || $password === '') {
            throw new RuntimeException(
                'Set TAPZY_MYSQL_TEST_PASSWORD for the isolated MySQL NFC/queue integration test.'
            );
        }

        return $password;
    }

    private function cleanupAfterSetupFailure(Throwable $setupException): never
    {
        try {
            $this->cleanupDatabase();
        } catch (Throwable $cleanupException) {
            throw new RuntimeException(
                'MySQL NFC/queue integration setup failed and cleanup also failed. Setup: '
                .$setupException->getMessage().' Cleanup: '.$cleanupException->getMessage(),
                0,
                $setupException
            );
        }

        throw $setupException;
    }

    private function cleanupDatabase(): void
    {
        $errors = [];

        try {
            if ($this->databaseIsolationVerified) {
                try {
                    $connection = DB::connection(self::CONNECTION_NAME);

                    $this->assertConnectionIdentity($connection);

                    $schema = Schema::connection(self::CONNECTION_NAME);

                    foreach (self::CLEANUP_TABLES as $table) {
                        try {
                            if ($schema->hasTable($table)) {
                                $schema->dropIfExists($table);
                            }
                        } catch (Throwable $exception) {
                            $errors[] = 'Failed to drop '.$table.': '.$exception->getMessage();
                        }
                    }

                    foreach (self::CLEANUP_TABLES as $table) {
                        try {
                            if ($schema->hasTable($table)) {
                                $errors[] = 'Table '.$table.' still exists after cleanup.';
                            }
                        } catch (Throwable $exception) {
                            $errors[] = 'Failed to verify cleanup of '.$table.': '.$exception->getMessage();
                        }
                    }
                } catch (Throwable $exception) {
                    $errors[] = 'Cleanup failed: '.$exception->getMessage();
                }
            }
        } finally {
            try {
                if ($this->originalDefaultConnection !== null) {
                    DB::setDefaultConnection($this->originalDefaultConnection);
                    $this->originalDefaultConnection = null;
                }
            } catch (Throwable $exception) {
                $errors[] = 'Failed to restore the default database connection: '.$exception->getMessage();
            }

            try {
                DB::purge(self::CONNECTION_NAME);
            } catch (Throwable $exception) {
                $errors[] = 'Failed to purge the isolated database connection: '.$exception->getMessage();
            }

            $this->databaseIsolationVerified = false;
        }

        if ($errors !== []) {
            throw new RuntimeException(
                'Isolated database cleanup failed: '.implode(' | ', $errors)
            );
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
