<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class NfcCardIntegrityConstraintsMigrationTest extends TestCase
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

        Schema::dropIfExists('nfc_cards');
        Schema::dropIfExists('stores');

        $this->migration('2026_11_06_000000_create_stores_table.php')->up();
        $this->migration('2026_11_06_000002_create_nfc_cards_table.php')->up();
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

    public function test_migration_rejects_duplicate_global_card_uid_without_changing_schema(): void
    {
        $storeA = $this->createStore('Store A');
        $storeB = $this->createStore('Store B');

        $this->insertCard($storeA, 'hardware-shared');
        $this->insertCard($storeB, 'hardware-shared');

        try {
            $this->integrityMigration()->up();
            $this->fail('Duplicate global card_uid must abort the migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('duplicate nfc_cards.card_uid', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('nfc_cards', 'active_store_id'));
        $this->assertFalse(Schema::hasIndex('nfc_cards', 'uq_card_uid_global'));
        $this->assertFalse(Schema::hasIndex('nfc_cards', 'uq_store_single_card'));
        $this->assertTrue(Schema::hasIndex('nfc_cards', 'uq_store_card_uid', 'unique'));
    }

    public function test_migration_rejects_multiple_non_deleted_cards_per_store(): void
    {
        $store = $this->createStore('Store A');

        $this->insertCard($store, 'hardware-001');
        $this->insertCard($store, 'hardware-002');

        try {
            $this->integrityMigration()->up();
            $this->fail('Multiple non-deleted cards per store must abort the migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('more than one non-deleted NFC card', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('nfc_cards', 'active_store_id'));
        $this->assertFalse(Schema::hasIndex('nfc_cards', 'uq_card_uid_global'));
        $this->assertFalse(Schema::hasIndex('nfc_cards', 'uq_store_single_card'));
        $this->assertTrue(Schema::hasIndex('nfc_cards', 'uq_store_card_uid', 'unique'));
    }

    public function test_migration_enforces_global_uid_and_single_non_deleted_card_per_store(): void
    {
        $storeA = $this->createStore('Store A');
        $storeB = $this->createStore('Store B');
        $firstCardId = $this->insertCard($storeA, 'hardware-001');

        $migration = $this->integrityMigration();
        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('nfc_cards', 'active_store_id'));
        $this->assertTrue(Schema::hasIndex('nfc_cards', 'uq_card_uid_global', 'unique'));
        $this->assertTrue(Schema::hasIndex('nfc_cards', 'uq_store_single_card', 'unique'));
        $this->assertFalse(Schema::hasIndex('nfc_cards', 'uq_store_card_uid', 'unique'));

        try {
            $this->insertCard($storeB, 'hardware-001');
            $this->fail('card_uid must be unique globally.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        try {
            $this->insertCard($storeA, 'hardware-002');
            $this->fail('A store must not have two non-deleted cards.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        DB::table('nfc_cards')->where('id', $firstCardId)->update(['deleted_at' => now()]);

        $replacementCardId = $this->insertCard($storeA, 'hardware-003');
        $this->assertGreaterThan($firstCardId, $replacementCardId);

        try {
            $this->insertCard($storeB, 'hardware-001');
            $this->fail('Soft-deleted card_uid must remain globally reserved.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_down_migration_restores_legacy_unique_constraint(): void
    {
        $storeA = $this->createStore('Store A');
        $storeB = $this->createStore('Store B');
        $this->insertCard($storeA, 'hardware-001');

        $migration = $this->integrityMigration();
        $migration->up();
        $migration->down();

        $this->assertFalse(Schema::hasColumn('nfc_cards', 'active_store_id'));
        $this->assertFalse(Schema::hasIndex('nfc_cards', 'uq_card_uid_global'));
        $this->assertFalse(Schema::hasIndex('nfc_cards', 'uq_store_single_card'));
        $this->assertTrue(Schema::hasIndex('nfc_cards', 'uq_store_card_uid', 'unique'));

        $this->insertCard($storeB, 'hardware-001');
        $this->insertCard($storeA, 'hardware-002');

        $this->assertSame(3, DB::table('nfc_cards')->count());
    }

    private function integrityMigration(): Migration
    {
        return $this->migration('2026_11_08_000000_add_nfc_card_integrity_constraints.php');
    }

    private function migration(string $filename): Migration
    {
        return require database_path('migrations/'.$filename);
    }

    private function createStore(string $name): int
    {
        return DB::table('stores')->insertGetId([
            'name' => $name,
            'slug' => 'store-'.bin2hex(random_bytes(6)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertCard(int $storeId, string $cardUid): int
    {
        return DB::table('nfc_cards')->insertGetId([
            'store_id' => $storeId,
            'card_uid' => $cardUid,
            'public_token_hash' => hash('sha256', bin2hex(random_bytes(20))),
            'label' => 'Card '.$cardUid,
            'type' => 'table',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
