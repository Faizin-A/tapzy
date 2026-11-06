<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class MySqlOrderItemsSchemaIntegrationTest extends TestCase
{
    private string $connectionName = 'tapzy_mysql_order_items_integration';

    protected function setUp(): void
    {
        parent::setUp();

        $database = getenv('TAPZY_MYSQL_TEST_DATABASE');

        if ($database !== 'tapzy_test_orders' || ! preg_match('/^tapzy_test_[A-Za-z0-9_]+$/', (string) $database)) {
            throw new RuntimeException('Order Item integration tests require TAPZY_MYSQL_TEST_DATABASE=tapzy_test_orders.');
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
            throw new RuntimeException('Refusing to run Order Item integration tests outside tapzy_test_orders.');
        }

        if (! preg_match('/^8\.4\./', $version)) {
            throw new RuntimeException('Order Item integration tests require MySQL 8.4; connected version: '.$version);
        }

        foreach (['stores', 'nfc_cards', 'queue_sessions', 'queues', 'products', 'orders', 'order_items'] as $table) {
            if (Schema::hasTable($table)) {
                throw new RuntimeException(
                    'The isolated tapzy_test_orders database must not already contain Order Item prerequisite tables.'
                );
            }
        }
    }

    public function test_mysql_order_items_schema_foreign_keys_and_snapshots(): void
    {
        $storesMigration = require database_path('migrations/2026_11_06_000000_create_stores_table.php');
        $cardsMigration = require database_path('migrations/2026_11_06_000002_create_nfc_cards_table.php');
        $sessionsMigration = require database_path('migrations/2026_11_06_000003_create_queue_sessions_table.php');
        $queuesMigration = require database_path('migrations/2026_11_06_000004_create_queues_table.php');
        $productsMigration = require database_path('migrations/2026_11_06_000005_create_products_table.php');
        $ordersMigration = require database_path('migrations/2026_11_06_000006_create_orders_table.php');
        $orderItemsMigration = require database_path('migrations/2026_11_06_000007_create_order_items_table.php');

        try {
            $storesMigration->up();
            $cardsMigration->up();
            $sessionsMigration->up();
            $queuesMigration->up();
            $productsMigration->up();
            $ordersMigration->up();
            $orderItemsMigration->up();

            $this->assertSame([
                'id', 'order_id', 'product_id', 'product_name_snapshot', 'unit_price_snapshot',
                'quantity', 'subtotal', 'notes', 'created_at', 'updated_at',
            ], Schema::getColumnListing('order_items'));

            $this->assertColumn('id', 'bigint unsigned', 'NO', null);
            $this->assertColumn('order_id', 'bigint unsigned', 'NO', null);
            $this->assertColumn('product_id', 'bigint unsigned', 'YES', null);
            $this->assertColumn('product_name_snapshot', 'varchar(150)', 'NO', null);
            $this->assertColumn('unit_price_snapshot', 'decimal(12,2)', 'NO', '0.00');
            $this->assertColumn('quantity', 'int unsigned', 'NO', '1');
            $this->assertColumn('subtotal', 'decimal(12,2)', 'NO', '0.00');
            $this->assertColumn('notes', 'varchar(255)', 'YES', null);
            $this->assertColumn('created_at', 'timestamp', 'YES', null);
            $this->assertColumn('updated_at', 'timestamp', 'YES', null);
            $this->assertFalse(Schema::hasColumn('order_items', 'deleted_at'));

            $this->assertIndex('order_items_order_id_index', ['order_id']);
            $this->assertIndex('order_items_product_id_index', ['product_id']);
            $this->assertForeignKey('order_id', 'orders', 'restrict');
            $this->assertForeignKey('product_id', 'products', 'set null');

            $store = Store::create([
                'name' => 'Order Item Test Store',
                'slug' => 'order-item-'.bin2hex(random_bytes(6)),
            ]);
            $product = $store->products()->create([
                'name' => 'Original Product Name',
                'price' => '18.75',
            ]);
            $order = $this->createOrder($store);
            $item = $this->createOrderItem($order, $product);

            $this->assertInstanceOf(Order::class, $item->order);
            $this->assertInstanceOf(Product::class, $item->product);
            $this->assertSame('Original Product Name', $item->product_name_snapshot);
            $this->assertSame('18.75', $item->unit_price_snapshot);

            try {
                DB::table('orders')->where('id', $order->id)->delete();
                $this->fail('Hard delete of an order with items must be rejected by ON DELETE RESTRICT.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }

            $product->update([
                'name' => 'Updated Product Name',
                'price' => '30.00',
            ]);
            $this->assertSame('Original Product Name', DB::table('order_items')->where('id', $item->id)->value('product_name_snapshot'));
            $this->assertSame('18.75', (string) DB::table('order_items')->where('id', $item->id)->value('unit_price_snapshot'));

            $product->delete();
            $this->assertSame($product->id, DB::table('order_items')->where('id', $item->id)->value('product_id'));

            Product::withTrashed()->findOrFail($product->id)->forceDelete();

            $this->assertNull(DB::table('order_items')->where('id', $item->id)->value('product_id'));
            $this->assertSame('Original Product Name', DB::table('order_items')->where('id', $item->id)->value('product_name_snapshot'));
            $this->assertSame('18.75', (string) DB::table('order_items')->where('id', $item->id)->value('unit_price_snapshot'));
        } finally {
            if (Schema::hasTable('order_items')) {
                $orderItemsMigration->down();
            }

            if (Schema::hasTable('orders')) {
                $ordersMigration->down();
            }

            if (Schema::hasTable('products')) {
                $productsMigration->down();
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
            ['order_items', $column]
        );

        $this->assertNotNull($actual);
        $this->assertSame($type, strtolower($actual->COLUMN_TYPE));
        $this->assertSame($nullable, $actual->IS_NULLABLE);
        $this->assertSame($default, $actual->COLUMN_DEFAULT === null ? null : (string) $actual->COLUMN_DEFAULT);
    }

    /** @param list<string> $columns */
    private function assertIndex(string $name, array $columns): void
    {
        $index = collect(Schema::getIndexes('order_items'))->firstWhere('name', $name);

        $this->assertNotNull($index, "Expected index {$name} to exist.");
        $this->assertSame($columns, $index['columns']);
        $this->assertFalse($index['unique']);
    }

    private function assertForeignKey(string $column, string $parentTable, string $onDelete): void
    {
        $foreignKey = collect(Schema::getForeignKeys('order_items'))
            ->first(fn (array $key) => $key['columns'] === [$column]);

        $this->assertNotNull($foreignKey);
        $this->assertSame($parentTable, $foreignKey['foreign_table']);
        $this->assertSame(['id'], $foreignKey['foreign_columns']);
        $this->assertSame($onDelete, $foreignKey['on_delete']);
        $this->assertSame('cascade', $foreignKey['on_update']);
    }

    private function createOrder(Store $store): Order
    {
        $order = new Order(['customer_name' => 'Customer']);
        $order->store_id = $store->id;
        $order->order_code = 'ORDER-'.bin2hex(random_bytes(6));
        $order->save();

        return $order;
    }

    private function createOrderItem(Order $order, Product $product): OrderItem
    {
        $item = $order->items()->make(['quantity' => 1]);
        $item->product_id = $product->id;
        $item->product_name_snapshot = $product->name;
        $item->unit_price_snapshot = $product->price;
        $item->subtotal = $product->price;
        $item->save();

        return $item;
    }
}
