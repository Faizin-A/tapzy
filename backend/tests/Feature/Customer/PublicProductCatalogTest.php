<?php

namespace Tests\Feature\Customer;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithPrefixedMySqlDatabase;
use Tests\TestCase;
use Throwable;

class PublicProductCatalogTest extends TestCase
{
    use InteractsWithPrefixedMySqlDatabase;

    private const ISOLATED_TABLES = [
        'products',
        'nfc_cards',
        'stores',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPrefixedMySqlDatabase('cust_catalog');

        try {
            foreach (self::ISOLATED_TABLES as $table) {
                Schema::dropIfExists($table);
            }

            Schema::create('stores', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 150);
                $table->string('slug', 150)->unique();
                $table->text('description')->nullable();
                $table->string('phone', 30)->nullable();
                $table->text('address')->nullable();
                $table->string('google_maps_url', 255)->nullable();
                $table->unsignedInteger('avg_service_time')->default(10);
                $table->string('queue_prefix', 5)->default('A');
                $table->string('ordering_mode')->default('queue_and_order');
                $table->boolean('is_active')->default(true);
                $table->dateTime('expires_at');
                $table->timestamps();
                $table->softDeletes();
            });

            Schema::create('nfc_cards', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('store_id')->constrained()->restrictOnDelete();
                $table->string('card_uid', 100)->unique();
                $table->char('public_token_hash', 64)->unique();
                $table->string('label', 100);
                $table->string('type')->default('table');
                $table->boolean('is_active')->default(false);
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('activated_at')->nullable();
                $table->timestamp('deactivated_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unsignedBigInteger('active_store_id')
                    ->nullable()
                    ->virtualAs('CASE WHEN deleted_at IS NULL THEN store_id ELSE NULL END');
                $table->unique('active_store_id');
            });

            Schema::create('products', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('store_id')->constrained()->restrictOnDelete()->cascadeOnUpdate();
                $table->string('name', 150);
                $table->text('description')->nullable();
                $table->decimal('price', 12, 2)->default(0.00);
                $table->string('category', 100)->nullable();
                $table->string('image_url', 255)->nullable();
                $table->boolean('is_available')->default(true);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
                $table->index('store_id');
                $table->index('category');
                $table->index(['store_id', 'is_active', 'is_available'], 'idx_store_product_catalog');
            });
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

    // -------------------------------------------------------------------------
    // Happy Path Tests
    // -------------------------------------------------------------------------

    public function test_returns_only_active_and_available_products(): void
    {
        $store = $this->createStore();
        $token = $this->token();
        $this->insertCard($store->id, $token);

        // Should appear
        $this->insertProduct($store->id, 'Kopi Susu', 'Espresso Based', 22000, isActive: true, isAvailable: true);
        $this->insertProduct($store->id, 'Matcha Latte', 'Non-Coffee', 28000, isActive: true, isAvailable: true);

        // Should NOT appear
        $this->insertProduct($store->id, 'Hidden Item', 'Espresso Based', 10000, isActive: false, isAvailable: true);
        $this->insertProduct($store->id, 'Out of Stock', 'Espresso Based', 10000, isActive: true, isAvailable: false);
        $this->insertProduct($store->id, 'Deleted Item', 'Non-Coffee', 10000, isActive: true, isAvailable: true, deletedAt: now());

        $response = $this->getJson("/api/v1/public/stores/{$token}/products");

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(2, $data['products']);
        $names = array_column($data['products'], 'name');
        $this->assertContains('Kopi Susu', $names);
        $this->assertContains('Matcha Latte', $names);
        $this->assertNotContains('Hidden Item', $names);
        $this->assertNotContains('Out of Stock', $names);
        $this->assertNotContains('Deleted Item', $names);
    }

    public function test_returns_http_200_with_empty_arrays_when_catalog_is_empty(): void
    {
        $store = $this->createStore();
        $token = $this->token();
        $this->insertCard($store->id, $token);

        // All products ineligible
        $this->insertProduct($store->id, 'Inactive', 'Foo', 1000, isActive: false, isAvailable: true);
        $this->insertProduct($store->id, 'No Stock', 'Foo', 1000, isActive: true, isAvailable: false);

        $response = $this->getJson("/api/v1/public/stores/{$token}/products");

        $response->assertOk()
            ->assertJsonPath('data.categories', [])
            ->assertJsonPath('data.products', []);
    }

    public function test_returns_http_200_with_empty_arrays_when_store_has_no_products(): void
    {
        $store = $this->createStore();
        $token = $this->token();
        $this->insertCard($store->id, $token);

        $response = $this->getJson("/api/v1/public/stores/{$token}/products");

        $response->assertOk()
            ->assertJsonPath('data.categories', [])
            ->assertJsonPath('data.products', []);
    }

    public function test_products_expose_public_id_for_future_order_items(): void
    {
        $store = $this->createStore();
        $token = $this->token();
        $this->insertCard($store->id, $token);

        $productId = $this->insertProduct($store->id, 'Kopi Susu', 'Minuman', 22000);

        $response = $this->getJson("/api/v1/public/stores/{$token}/products");

        $response->assertOk()
            ->assertJsonPath('data.products.0.id', $productId)
            ->assertJsonPath('data.products.0.name', 'Kopi Susu')
            ->assertJsonPath('data.products.0.price', '22000.00');
    }

    public function test_queue_only_store_rejects_catalog_with_ordering_disabled(): void
    {
        $store = $this->createStore(orderingMode: 'queue_only');
        $token = $this->token();
        $this->insertCard($store->id, $token);

        $this->insertProduct($store->id, 'Kopi Susu', 'Minuman', 22000);

        $this->getJson("/api/v1/public/stores/{$token}/products")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'ORDERING_DISABLED')
            ->assertJsonPath('message', 'Toko ini tidak mengaktifkan fitur pemesanan.');
    }

    public function test_returns_unique_sorted_non_null_categories(): void
    {
        $store = $this->createStore();
        $token = $this->token();
        $this->insertCard($store->id, $token);

        $this->insertProduct($store->id, 'Product A', 'Makanan', 10000);
        $this->insertProduct($store->id, 'Product B', 'Makanan', 10000);  // duplicate category
        $this->insertProduct($store->id, 'Product C', 'Minuman', 15000);
        $this->insertProduct($store->id, 'Product D', 'Dessert', 20000);

        $response = $this->getJson("/api/v1/public/stores/{$token}/products");

        $response->assertOk();
        $categories = $response->json('data.categories');

        $this->assertSame(['Dessert', 'Makanan', 'Minuman'], $categories);  // sorted, unique
    }

    public function test_handles_null_category_correctly(): void
    {
        $store = $this->createStore();
        $token = $this->token();
        $this->insertCard($store->id, $token);

        $this->insertProduct($store->id, 'Product With Category', 'Makanan', 10000);
        $this->insertProduct($store->id, 'Product Without Category', null, 5000);

        $response = $this->getJson("/api/v1/public/stores/{$token}/products");

        $response->assertOk();
        $categories = $response->json('data.categories');
        $products = $response->json('data.products');

        // null must NOT appear in categories array
        $this->assertNotContains(null, $categories);
        $this->assertSame(['Makanan'], $categories);

        // Product with null category must still appear in products list
        $this->assertCount(2, $products);
        $nullCategoryProduct = collect($products)->firstWhere('name', 'Product Without Category');
        $this->assertNotNull($nullCategoryProduct);
        $this->assertNull($nullCategoryProduct['category']);
    }

    public function test_category_filter_returns_matching_products_only(): void
    {
        $store = $this->createStore();
        $token = $this->token();
        $this->insertCard($store->id, $token);

        $this->insertProduct($store->id, 'Espresso', 'Espresso Based', 18000);
        $this->insertProduct($store->id, 'Cappuccino', 'Espresso Based', 22000);
        $this->insertProduct($store->id, 'Green Tea', 'Non-Coffee', 20000);

        $response = $this->getJson("/api/v1/public/stores/{$token}/products?category=Espresso+Based");

        $response->assertOk();
        $products = $response->json('data.products');

        $this->assertCount(2, $products);
        $names = array_column($products, 'name');
        $this->assertContains('Espresso', $names);
        $this->assertContains('Cappuccino', $names);
        $this->assertNotContains('Green Tea', $names);

        // Categories list must still reflect the FULL eligible catalog
        $categories = $response->json('data.categories');
        $this->assertContains('Non-Coffee', $categories);
    }

    public function test_search_filter_is_case_insensitive_and_returns_matching_products(): void
    {
        $store = $this->createStore();
        $token = $this->token();
        $this->insertCard($store->id, $token);

        $this->insertProduct($store->id, 'Kopi Susu Gula Aren', 'Coffee', 22000);
        $this->insertProduct($store->id, 'Es Kopi Vietnam', 'Coffee', 25000);
        $this->insertProduct($store->id, 'Matcha Latte', 'Non-Coffee', 28000);

        $response = $this->getJson("/api/v1/public/stores/{$token}/products?search=kopi");

        $response->assertOk();
        $products = $response->json('data.products');

        $this->assertCount(2, $products);
        $names = array_column($products, 'name');
        $this->assertContains('Kopi Susu Gula Aren', $names);
        $this->assertContains('Es Kopi Vietnam', $names);
        $this->assertNotContains('Matcha Latte', $names);
    }

    public function test_does_not_update_last_used_at_when_fetching_products(): void
    {
        $store = $this->createStore();
        $token = $this->token();
        $cardId = $this->insertCard($store->id, $token);

        $this->getJson("/api/v1/public/stores/{$token}/products")->assertOk();
        $this->getJson("/api/v1/public/stores/{$token}/products")->assertOk();

        $lastUsedAt = DB::table('nfc_cards')->where('id', $cardId)->value('last_used_at');
        $this->assertNull($lastUsedAt, 'last_used_at must not be updated by product catalog polling');
    }

    public function test_query_parameter_validation_boundaries(): void
    {
        $store = $this->createStore();
        $token = $this->token();
        $this->insertCard($store->id, $token);

        // 1. Missing parameters -> Accepted (200)
        $this->getJson("/api/v1/public/stores/{$token}/products")->assertOk();

        // 2. Empty parameters -> Accepted (200)
        $this->getJson("/api/v1/public/stores/{$token}/products?category=&search=")->assertOk();

        // 3. Category 100 chars -> Accepted (200)
        $cat100 = str_repeat('a', 100);
        $this->getJson("/api/v1/public/stores/{$token}/products?category={$cat100}")->assertOk();

        // 4. Category 101 chars -> Unprocessable (422)
        $cat101 = str_repeat('a', 101);
        $this->getJson("/api/v1/public/stores/{$token}/products?category={$cat101}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category');

        // 5. Search 100 chars -> Accepted (200)
        $search100 = str_repeat('b', 100);
        $this->getJson("/api/v1/public/stores/{$token}/products?search={$search100}")->assertOk();

        // 6. Search 101 chars -> Unprocessable (422)
        $search101 = str_repeat('b', 101);
        $this->getJson("/api/v1/public/stores/{$token}/products?search={$search101}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('search');
    }

    // -------------------------------------------------------------------------
    // Security / Tenant Isolation Tests
    // -------------------------------------------------------------------------

    public function test_category_filter_does_not_leak_products_from_other_store(): void
    {
        $storeA = $this->createStore();
        $tokenA = $this->token();
        $this->insertCard($storeA->id, $tokenA);

        $storeB = $this->createStore();
        // Store B does NOT have an NFC card in this test; we don't expose its token

        $this->insertProduct($storeA->id, 'Store A Espresso', 'Espresso Based', 18000);
        $this->insertProduct($storeB->id, 'Store B Espresso', 'Espresso Based', 20000);  // must NOT appear

        $response = $this->getJson("/api/v1/public/stores/{$tokenA}/products?category=Espresso+Based");

        $response->assertOk();
        $products = $response->json('data.products');

        $this->assertCount(1, $products);
        $this->assertSame('Store A Espresso', $products[0]['name']);
    }

    public function test_search_filter_does_not_leak_products_from_other_store(): void
    {
        $storeA = $this->createStore();
        $tokenA = $this->token();
        $this->insertCard($storeA->id, $tokenA);

        $storeB = $this->createStore();

        $this->insertProduct($storeA->id, 'Signature Blend A', 'Coffee', 25000);
        $this->insertProduct($storeB->id, 'Signature Blend B', 'Coffee', 25000);  // must NOT appear

        $response = $this->getJson("/api/v1/public/stores/{$tokenA}/products?search=Signature");

        $response->assertOk();
        $products = $response->json('data.products');

        $this->assertCount(1, $products);
        $this->assertSame('Signature Blend A', $products[0]['name']);
    }

    public function test_response_does_not_expose_internal_sensitive_fields(): void
    {
        $store = $this->createStore();
        $token = $this->token();
        $this->insertCard($store->id, $token);
        $this->insertProduct($store->id, 'Test Product', 'Category', 10000);

        $response = $this->getJson("/api/v1/public/stores/{$token}/products");

        $response->assertOk();
        $response->assertJsonMissingPath('data.store_id');
        $response->assertJsonMissingPath('data.products.0.store_id');
        $response->assertJsonMissingPath('data.products.0.deleted_at');
        $response->assertJsonMissingPath('data.products.0.is_active');
        $response->assertJsonMissingPath('data.card_uid');
        $response->assertJsonMissingPath('data.public_token_hash');
    }

    // -------------------------------------------------------------------------
    // Invalid Token / Store State Tests
    // -------------------------------------------------------------------------

    public function test_returns_404_on_invalid_token_format(): void
    {
        $this->getJson('/api/v1/public/stores/too-short/products')->assertNotFound();
        $this->getJson('/api/v1/public/stores/'.str_repeat('!', 40).'/products')->assertNotFound();
        $this->getJson('/api/v1/public/stores/'.str_repeat('A', 39).'/products')->assertNotFound();
    }

    public function test_returns_404_on_nonexistent_token(): void
    {
        $this->getJson('/api/v1/public/stores/'.str_repeat('Z', 40).'/products')->assertNotFound();
    }

    public function test_returns_404_when_card_is_inactive(): void
    {
        $store = $this->createStore();
        $token = $this->token();
        $this->insertCard($store->id, $token, active: false);

        $this->getJson("/api/v1/public/stores/{$token}/products")->assertNotFound();
    }

    public function test_returns_404_when_card_is_soft_deleted(): void
    {
        $store = $this->createStore();
        $token = $this->token();
        $this->insertCard($store->id, $token, deleted: true);

        $this->getJson("/api/v1/public/stores/{$token}/products")->assertNotFound();
    }

    public function test_returns_404_when_store_is_inactive(): void
    {
        $store = $this->createStore(isActive: false);
        $token = $this->token();
        $this->insertCard($store->id, $token);

        $this->getJson("/api/v1/public/stores/{$token}/products")->assertNotFound();
    }

    public function test_returns_404_when_store_is_expired(): void
    {
        $store = $this->createStore(expiresAt: now()->subHour());
        $token = $this->token();
        $this->insertCard($store->id, $token);

        $this->getJson("/api/v1/public/stores/{$token}/products")->assertNotFound();
    }

    public function test_returns_404_when_store_is_soft_deleted(): void
    {
        $store = $this->createStore();
        $token = $this->token();
        $this->insertCard($store->id, $token);

        DB::table('stores')->where('id', $store->id)->update(['deleted_at' => now()]);

        $this->getJson("/api/v1/public/stores/{$token}/products")->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // Rate Limiting Test
    // -------------------------------------------------------------------------

    public function test_rate_limiting_enforced_on_products_endpoint(): void
    {
        $store = $this->createStore();
        $token = $this->token();
        $this->insertCard($store->id, $token);

        // Hit the per-IP-token limit (30/min)
        for ($i = 0; $i < 30; $i++) {
            $this->getJson("/api/v1/public/stores/{$token}/products")->assertOk();
        }

        $this->getJson("/api/v1/public/stores/{$token}/products")->assertStatus(429);
    }

    // -------------------------------------------------------------------------
    // Helper Methods
    // -------------------------------------------------------------------------

    private function createStore(
        bool $isActive = true,
        mixed $expiresAt = null,
        string $orderingMode = 'queue_and_order',
    ): object {
        $id = DB::table('stores')->insertGetId([
            'name' => 'Store '.bin2hex(random_bytes(4)),
            'slug' => 'store-'.bin2hex(random_bytes(6)),
            'ordering_mode' => $orderingMode,
            'is_active' => $isActive,
            'expires_at' => $expiresAt ? $expiresAt->utc()->format('Y-m-d H:i:s') : now()->addYear()->utc()->format('Y-m-d H:i:s'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (object) DB::table('stores')->where('id', $id)->first();
    }

    private function insertCard(
        int $storeId,
        string $token,
        bool $active = true,
        bool $deleted = false,
    ): int {
        return DB::table('nfc_cards')->insertGetId([
            'store_id' => $storeId,
            'card_uid' => 'hw-'.bin2hex(random_bytes(6)),
            'public_token_hash' => hash('sha256', $token),
            'label' => 'Meja 01',
            'type' => 'table',
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $deleted ? now() : null,
        ]);
    }

    private function insertProduct(
        int $storeId,
        string $name,
        ?string $category,
        float $price,
        bool $isActive = true,
        bool $isAvailable = true,
        mixed $deletedAt = null,
    ): int {
        return DB::table('products')->insertGetId([
            'store_id' => $storeId,
            'name' => $name,
            'description' => null,
            'price' => $price,
            'category' => $category,
            'image_url' => null,
            'is_active' => $isActive,
            'is_available' => $isAvailable,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $deletedAt,
        ]);
    }

    private function token(): string
    {
        return bin2hex(random_bytes(20));
    }
}
