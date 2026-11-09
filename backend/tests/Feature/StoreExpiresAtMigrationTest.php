<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StoreExpiresAtMigrationTest extends TestCase
{
    private string $originalConnection;

    private string $connectionName;

    private string $tablePrefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = (string) config('database.default');
        $this->connectionName = 'tapzy_mysql_migration_'.bin2hex(random_bytes(4));
        $this->tablePrefix = 'mig_'.bin2hex(random_bytes(4)).'_';

        $config = config('database.connections.'.$this->originalConnection);
        $config['prefix'] = $this->tablePrefix;

        config(['database.connections.'.$this->connectionName => $config]);
        DB::purge($this->connectionName);
        DB::setDefaultConnection($this->connectionName);

        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame('tapzy_test', $connection->getDatabaseName());
        $this->assertSame('127.0.0.1', $connection->getConfig('host'));
        $this->assertSame('3307', (string) $connection->getConfig('port'));
        $this->assertSame($this->tablePrefix, $connection->getTablePrefix());

        Schema::dropIfExists('stores');

        $storesMigration = require database_path('migrations/2026_11_06_000000_create_stores_table.php');
        $storesMigration->up();
    }

    protected function tearDown(): void
    {
        if (isset($this->connectionName) && config('database.connections.'.$this->connectionName) !== null) {
            DB::setDefaultConnection($this->connectionName);

            if (DB::connection()->getTablePrefix() === $this->tablePrefix) {
                DB::purge($this->connectionName);
                Schema::dropIfExists('nfc_cards');
                Schema::dropIfExists('stores');
            }

            DB::purge($this->connectionName);
            DB::setDefaultConnection($this->originalConnection);
        }

        parent::tearDown();
    }

    public function test_migration_backfills_legacy_stores_and_enforces_not_null_expiry(): void
    {
        DB::table('stores')->insert([
            'name' => 'Legacy Store',
            'slug' => 'legacy-store',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_11_07_000000_add_expires_at_to_stores_table.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumn('stores', 'expires_at'));
        $this->assertTrue(Schema::hasIndex('stores', 'stores_expires_at_index'));

        $column = collect(Schema::getColumns('stores'))->firstWhere('name', 'expires_at');
        $this->assertNotNull($column);
        $this->assertFalse($column['nullable']);
        $this->assertStringContainsString('datetime', strtolower($column['type']));

        $this->assertSame(
            '2099-12-31 23:59:59',
            DB::table('stores')->where('slug', 'legacy-store')->value('expires_at')
        );
        $this->assertSame(1, DB::table('stores')->count());

        try {
            DB::table('stores')->insert([
                'name' => 'Missing Expiry',
                'slug' => 'missing-expiry',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('A store without expires_at must be rejected after the migration.');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('stores')->count());
        }
    }

    public function test_migration_is_idempotent_and_rollback_only_drops_the_new_column(): void
    {
        DB::table('stores')->insert([
            'name' => 'Legacy Store',
            'slug' => 'legacy-store',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_11_07_000000_add_expires_at_to_stores_table.php');
        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('stores', 'expires_at'));
        $this->assertTrue(Schema::hasIndex('stores', 'stores_expires_at_index'));

        $migration->down();

        $this->assertFalse(Schema::hasColumn('stores', 'expires_at'));
        $this->assertFalse(Schema::hasIndex('stores', 'stores_expires_at_index'));
        $this->assertSame(1, DB::table('stores')->count());
        $this->assertSame('Legacy Store', DB::table('stores')->value('name'));
    }
}
