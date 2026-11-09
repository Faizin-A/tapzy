<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class MySqlProductsSchemaIntegrationTest extends TestCase
{
    private string $connectionName = 'tapzy_mysql_products_integration';

    protected function setUp(): void
    {
        parent::setUp();

        $database = getenv('TAPZY_MYSQL_TEST_DATABASE');

        if ($database !== 'tapzy_test_products' || ! preg_match('/^tapzy_test_[A-Za-z0-9_]+$/', (string) $database)) {
            throw new RuntimeException('Product integration tests require TAPZY_MYSQL_TEST_DATABASE=tapzy_test_products.');
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

        if ($actualDatabase !== 'tapzy_test_products') {
            throw new RuntimeException('Refusing to run product integration tests outside tapzy_test_products.');
        }

        if (! preg_match('/^8\.4\./', $version)) {
            throw new RuntimeException('Product integration tests require MySQL 8.4; connected version: '.$version);
        }

        foreach (['stores', 'products'] as $table) {
            if (Schema::hasTable($table)) {
                throw new RuntimeException(
                    'The isolated tapzy_test_products database must not already contain stores or products tables.'
                );
            }
        }
    }

    public function test_mysql_products_schema_foreign_key_indexes_and_soft_deletes(): void
    {
        $storesMigration = require database_path('migrations/2026_11_06_000000_create_stores_table.php');
        $expiresAtMigration = require database_path('migrations/2026_11_07_000000_add_expires_at_to_stores_table.php');
        $productsMigration = require database_path('migrations/2026_11_06_000005_create_products_table.php');

        try {
            $storesMigration->up();
            $expiresAtMigration->up();
            $productsMigration->up();

            $this->assertSame([
                'id', 'store_id', 'name', 'description', 'price', 'category', 'image_url',
                'is_available', 'is_active', 'created_at', 'updated_at', 'deleted_at',
            ], Schema::getColumnListing('products'));

            $this->assertColumn('id', 'bigint unsigned', 'NO', null);
            $this->assertColumn('store_id', 'bigint unsigned', 'NO', null);
            $this->assertColumn('name', 'varchar(150)', 'NO', null);
            $this->assertColumn('description', 'text', 'YES', null);
            $this->assertColumn('price', 'decimal(12,2)', 'NO', '0.00');
            $this->assertColumn('category', 'varchar(100)', 'YES', null);
            $this->assertColumn('image_url', 'varchar(255)', 'YES', null);
            $this->assertColumn('is_available', 'tinyint(1)', 'NO', '1');
            $this->assertColumn('is_active', 'tinyint(1)', 'NO', '1');
            $this->assertColumn('created_at', 'timestamp', 'YES', null);
            $this->assertColumn('updated_at', 'timestamp', 'YES', null);
            $this->assertColumn('deleted_at', 'timestamp', 'YES', null);

            $this->assertIndex('products_store_id_index', ['store_id']);
            $this->assertIndex('products_category_index', ['category']);
            $this->assertIndex('products_is_available_index', ['is_available']);
            $this->assertIndex('products_is_active_index', ['is_active']);
            $this->assertIndex('products_deleted_at_index', ['deleted_at']);
            $this->assertIndex('idx_store_product_catalog', ['store_id', 'is_active', 'is_available']);
            $this->assertStoreForeignKey();

            $store = Store::create([
                'name' => 'Product Integration Store',
                'slug' => 'product-integration-'.bin2hex(random_bytes(6)),
                'expires_at' => now()->addYear(),
            ]);
            $product = $store->products()->create([
                'name' => 'Integration product',
                'price' => '12500.25',
                'is_active' => true,
                'is_available' => true,
            ]);

            try {
                DB::table('stores')->where('id', $store->id)->delete();
                $this->fail('A store with products must be protected by ON DELETE RESTRICT.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }

            $product->delete();

            $this->assertTrue(DB::table('products')->where('id', $product->id)->exists());
            $this->assertNotNull(DB::table('products')->where('id', $product->id)->value('deleted_at'));
            $this->assertNotNull(Product::withTrashed()->find($product->id));

            try {
                DB::table('stores')->where('id', $store->id)->delete();
                $this->fail('Soft deletion must not remove the product FK protection.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        } finally {
            if (Schema::hasTable('products')) {
                $productsMigration->down();
            }

            if (Schema::hasTable('stores')) {
                $expiresAtMigration->down();
                $storesMigration->down();
            }
        }
    }

    private function assertColumn(string $column, string $type, string $nullable, ?string $default): void
    {
        $actual = DB::selectOne(
            'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['products', $column]
        );

        $this->assertNotNull($actual);
        $this->assertSame($type, strtolower($actual->COLUMN_TYPE));
        $this->assertSame($nullable, $actual->IS_NULLABLE);
        $this->assertSame($default, $actual->COLUMN_DEFAULT === null ? null : (string) $actual->COLUMN_DEFAULT);
    }

    /** @param list<string> $columns */
    private function assertIndex(string $name, array $columns): void
    {
        $index = collect(Schema::getIndexes('products'))->firstWhere('name', $name);

        $this->assertNotNull($index, "Expected index {$name} to exist.");
        $this->assertSame($columns, $index['columns']);
        $this->assertFalse($index['unique']);
    }

    private function assertStoreForeignKey(): void
    {
        $foreignKey = collect(Schema::getForeignKeys('products'))
            ->first(fn (array $key) => $key['columns'] === ['store_id']);

        $this->assertNotNull($foreignKey);
        $this->assertSame('stores', $foreignKey['foreign_table']);
        $this->assertSame(['id'], $foreignKey['foreign_columns']);
        $this->assertSame('restrict', $foreignKey['on_delete']);
        $this->assertSame('cascade', $foreignKey['on_update']);
    }
}
