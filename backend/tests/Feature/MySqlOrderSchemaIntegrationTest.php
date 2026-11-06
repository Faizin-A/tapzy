<?php

namespace Tests\Feature;

use App\Models\NfcCard;
use App\Models\Order;
use App\Models\Queue;
use App\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class MySqlOrderSchemaIntegrationTest extends TestCase
{
    private string $connectionName = 'tapzy_mysql_orders_integration';

    protected function setUp(): void
    {
        parent::setUp();

        $database = getenv('TAPZY_MYSQL_TEST_DATABASE');

        if ($database !== 'tapzy_test_orders' || ! preg_match('/^tapzy_test_[A-Za-z0-9_]+$/', (string) $database)) {
            throw new RuntimeException('Order integration tests require TAPZY_MYSQL_TEST_DATABASE=tapzy_test_orders.');
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

        if ($actualDatabase !== 'tapzy_test_orders') {
            throw new RuntimeException('Refusing to run order integration tests outside tapzy_test_orders.');
        }

        if (! preg_match('/^8\.4\./', $version)) {
            throw new RuntimeException('Order integration tests require MySQL 8.4; connected version: '.$version);
        }

        foreach (['stores', 'nfc_cards', 'queue_sessions', 'queues', 'orders'] as $table) {
            if (Schema::hasTable($table)) {
                throw new RuntimeException(
                    'The isolated tapzy_test_orders database must not already contain order prerequisite tables.'
                );
            }
        }
    }

    public function test_mysql_order_schema_constraints_and_foreign_keys(): void
    {
        $storesMigration = require database_path('migrations/2026_11_06_000000_create_stores_table.php');
        $cardsMigration = require database_path('migrations/2026_11_06_000002_create_nfc_cards_table.php');
        $sessionsMigration = require database_path('migrations/2026_11_06_000003_create_queue_sessions_table.php');
        $queuesMigration = require database_path('migrations/2026_11_06_000004_create_queues_table.php');
        $ordersMigration = require database_path('migrations/2026_11_06_000006_create_orders_table.php');

        try {
            $storesMigration->up();
            $cardsMigration->up();
            $sessionsMigration->up();
            $queuesMigration->up();
            $ordersMigration->up();

            $this->assertSame([
                'id', 'order_code', 'store_id', 'queue_id', 'nfc_card_id', 'customer_name', 'total_items',
                'subtotal', 'tax_amount', 'discount_amount', 'total_price', 'status', 'completed_at', 'notes',
                'created_at', 'updated_at', 'deleted_at',
            ], Schema::getColumnListing('orders'));

            $this->assertColumn('id', 'bigint unsigned', 'NO', null);
            $this->assertColumn('order_code', 'varchar(50)', 'NO', null);
            $this->assertColumn('store_id', 'bigint unsigned', 'NO', null);
            $this->assertColumn('queue_id', 'bigint unsigned', 'YES', null);
            $this->assertColumn('nfc_card_id', 'bigint unsigned', 'YES', null);
            $this->assertColumn('customer_name', 'varchar(100)', 'YES', null);
            $this->assertColumn('total_items', 'int unsigned', 'NO', '0');
            $this->assertColumn('subtotal', 'decimal(12,2)', 'NO', '0.00');
            $this->assertColumn('tax_amount', 'decimal(12,2)', 'NO', '0.00');
            $this->assertColumn('discount_amount', 'decimal(12,2)', 'NO', '0.00');
            $this->assertColumn('total_price', 'decimal(12,2)', 'NO', '0.00');
            $this->assertColumn(
                'status',
                "enum('pending','confirmed','preparing','ready','completed','cancelled')",
                'NO',
                'pending'
            );
            $this->assertColumn('completed_at', 'timestamp', 'YES', null);
            $this->assertColumn('notes', 'text', 'YES', null);
            $this->assertColumn('created_at', 'timestamp', 'YES', null);
            $this->assertColumn('updated_at', 'timestamp', 'YES', null);
            $this->assertColumn('deleted_at', 'timestamp', 'YES', null);

            $this->assertFalse(Schema::hasColumn('orders', 'payment_status'));
            $this->assertFalse(Schema::hasColumn('orders', 'payment_method'));
            $this->assertFalse(Schema::hasColumn('orders', 'paid_at'));

            $this->assertIndex('orders_order_code_unique', ['order_code'], true);
            $this->assertIndex('orders_store_id_index', ['store_id']);
            $this->assertIndex('uq_orders_queue_id', ['queue_id'], true);
            $this->assertIndex('orders_nfc_card_id_index', ['nfc_card_id']);
            $this->assertIndex('orders_status_index', ['status']);
            $this->assertIndex('orders_completed_at_index', ['completed_at']);
            $this->assertIndex('orders_deleted_at_index', ['deleted_at']);
            $this->assertIndex('idx_store_order_search', ['store_id', 'status', 'created_at']);

            $this->assertForeignKey('store_id', 'stores', 'restrict');
            $this->assertForeignKey('queue_id', 'queues', 'restrict');
            $this->assertForeignKey('nfc_card_id', 'nfc_cards', 'set null');

            $store = $this->createStore('Order test store');
            $card = $store->nfcCards()->create([
                'card_uid' => 'order-card-'.bin2hex(random_bytes(5)),
                'public_token_hash' => hash('sha256', random_bytes(32)),
                'label' => 'Order test card',
            ]);
            $queue = $this->createQueue($store, $card);

            $queueOrderId = $this->insertOrder('QUEUED-ORDER-1', $store->id, $queue->id, $card->id);
            $directOrderId = $this->insertOrder('DIRECT-ORDER-1', $store->id, null, $card->id);
            $this->insertOrder('DIRECT-ORDER-2', $store->id, null, $card->id);

            $this->assertSame(2, DB::table('orders')->whereNull('queue_id')->count());
            $this->assertSame(0, (int) DB::table('orders')->where('id', $directOrderId)->value('total_items'));
            $this->assertSame('pending', DB::table('orders')->where('id', $directOrderId)->value('status'));

            try {
                $this->insertOrder('QUEUED-ORDER-2', $store->id, $queue->id, null);
                $this->fail('A queue must not be linked to more than one order.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }

            try {
                $this->insertOrder('DIRECT-ORDER-1', $store->id, null, null);
                $this->fail('order_code must be unique.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }

            try {
                $this->insertOrder('INVALID-STORE-ORDER', 999999, null, null);
                $this->fail('An order with a missing store must be rejected.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }

            try {
                $this->insertOrder('INVALID-QUEUE-ORDER', $store->id, 999999, null);
                $this->fail('An order with a missing queue must be rejected.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }

            try {
                $this->insertOrder('INVALID-CARD-ORDER', $store->id, null, 999999);
                $this->fail('An order with a missing NFC card must be rejected.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }

            DB::table('nfc_cards')->where('id', $card->id)->delete();

            $queueAfterCardDeletion = DB::table('queues')->where('id', $queue->id)->first();
            $this->assertNotNull($queueAfterCardDeletion);
            $this->assertNull($queueAfterCardDeletion->nfc_card_id);
            $this->assertSame('nfc', $queueAfterCardDeletion->source);

            $queuedOrderAfterCardDeletion = DB::table('orders')->where('id', $queueOrderId)->first();
            $this->assertNotNull($queuedOrderAfterCardDeletion);
            $this->assertNull($queuedOrderAfterCardDeletion->nfc_card_id);

            $directOrderAfterCardDeletion = DB::table('orders')->where('id', $directOrderId)->first();
            $this->assertNotNull($directOrderAfterCardDeletion);
            $this->assertNull($directOrderAfterCardDeletion->nfc_card_id);

            $queuedOrder = Order::findOrFail($queueOrderId);
            $queuedOrder->delete();
            $this->assertTrue(DB::table('orders')->where('id', $queueOrderId)->whereNotNull('deleted_at')->exists());

            try {
                $this->insertOrder('QUEUED-ORDER-AFTER-SOFT-DELETE', $store->id, $queue->id, null);
                $this->fail('The unique queue/order relation must also cover soft-deleted orders.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }

            try {
                DB::table('queues')->where('id', $queue->id)->delete();
                $this->fail('A queue with an order must be protected by ON DELETE RESTRICT.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }

            try {
                DB::table('stores')->where('id', $store->id)->delete();
                $this->fail('A store with orders must be protected by ON DELETE RESTRICT.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        } finally {
            if (Schema::hasTable('orders')) {
                $ordersMigration->down();
            }

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
                $storesMigration->down();
            }
        }
    }

    private function assertColumn(string $column, string $type, string $nullable, ?string $default): void
    {
        $actual = DB::selectOne(
            'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['orders', $column]
        );

        $this->assertNotNull($actual);
        $this->assertSame($type, strtolower($actual->COLUMN_TYPE));
        $this->assertSame($nullable, $actual->IS_NULLABLE);
        $this->assertSame($default, $actual->COLUMN_DEFAULT === null ? null : (string) $actual->COLUMN_DEFAULT);
    }

    /** @param list<string> $columns */
    private function assertIndex(string $name, array $columns, bool $unique = false): void
    {
        $index = collect(Schema::getIndexes('orders'))->firstWhere('name', $name);

        $this->assertNotNull($index, "Expected index {$name} to exist.");
        $this->assertSame($columns, $index['columns']);
        $this->assertSame($unique, $index['unique']);
    }

    private function assertForeignKey(string $column, string $parentTable, string $onDelete): void
    {
        $foreignKey = collect(Schema::getForeignKeys('orders'))
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
            'slug' => 'orders-test-'.bin2hex(random_bytes(6)),
        ]);
    }

    private function createQueue(Store $store, NfcCard $card): Queue
    {
        $session = $store->queueSessions()->create([
            'session_code' => 'ORDER-SESSION-'.bin2hex(random_bytes(4)),
            'session_date' => '2026-11-06',
            'opened_at' => now(),
        ]);
        $queue = $session->queues()->make();
        $queue->store_id = $store->id;
        $queue->nfc_card_id = $card->id;
        $queue->queue_number = random_int(1, 900000);
        $queue->formatted_number = 'Q-'.bin2hex(random_bytes(3));
        $queue->source = 'nfc';
        $queue->access_token_hash = hash('sha256', random_bytes(32));
        $queue->save();

        return $queue;
    }

    private function insertOrder(string $code, int $storeId, ?int $queueId, ?int $cardId): int
    {
        return DB::table('orders')->insertGetId([
            'order_code' => $code,
            'store_id' => $storeId,
            'queue_id' => $queueId,
            'nfc_card_id' => $cardId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
