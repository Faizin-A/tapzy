<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());

        Schema::dropIfExists('products');
        Schema::dropIfExists('stores');

        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 150)->unique();
            $table->dateTime('expires_at');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2)->default(0.00);
            $table->string('category', 100)->nullable();
            $table->string('image_url', 255)->nullable();
            $table->boolean('is_available')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function test_product_and_store_relationships_work_in_both_directions(): void
    {
        $store = $this->createStore();
        $this->useTenant($store);
        $product = $store->products()->create([
            'name' => 'Coffee',
            'price' => '12500.00',
        ]);

        $this->assertInstanceOf(Store::class, $product->store);
        $this->assertSame($store->id, $product->store->id);
        $this->assertTrue($store->products->contains('id', $product->id));
    }

    public function test_product_price_and_status_attributes_are_cast(): void
    {
        $product = new Product([
            'name' => 'Tea',
            'price' => '123.4',
            'is_active' => 1,
            'is_available' => 0,
        ]);

        $this->assertSame('123.40', $product->price);
        $this->assertTrue($product->is_active);
        $this->assertFalse($product->is_available);
    }

    public function test_product_soft_delete_preserves_row_and_with_trashed_can_retrieve_it(): void
    {
        $store = $this->createStore();
        $this->useTenant($store);
        $product = $store->products()->create([
            'name' => 'Archived item',
            'price' => '42.00',
        ]);

        $product->delete();

        $this->assertNull(Product::find($product->id));
        $this->assertNotNull(Product::withTrashed()->find($product->id)?->deleted_at);
        $this->assertTrue(DB::table('products')->where('id', $product->id)->whereNotNull('deleted_at')->exists());
    }

    public function test_store_id_is_not_assignable_through_normal_mass_assignment(): void
    {
        $product = new Product([
            'store_id' => 99,
            'name' => 'Client-supplied tenant',
        ]);

        $this->assertNull($product->store_id);
        $this->assertSame('Client-supplied tenant', $product->name);
    }

    public function test_product_queries_fail_closed_without_tenant_context(): void
    {
        $store = $this->createStore();
        $product = $store->products()->create([
            'name' => 'Hidden without context',
            'price' => '10.00',
        ]);

        $this->assertFalse(Product::query()->whereKey($product->id)->exists());
    }

    public function test_product_queries_are_limited_to_the_current_store(): void
    {
        $firstStore = $this->createStore();
        $firstProduct = $firstStore->products()->create([
            'name' => 'First tenant item',
            'price' => '10.00',
        ]);
        $secondStore = $this->createStore();
        $secondProduct = $secondStore->products()->create([
            'name' => 'Second tenant item',
            'price' => '20.00',
        ]);

        $this->useTenant($firstStore);

        $this->assertNotNull(Product::query()->find($firstProduct->id));
        $this->assertNull(Product::query()->find($secondProduct->id));
    }

    private function createStore(): Store
    {
        return Store::create([
            'name' => 'Product Test Store',
            'slug' => 'product-store-'.bin2hex(random_bytes(5)),
            'expires_at' => now()->addYear(),
        ]);
    }

    private function useTenant(Store $store): void
    {
        $store->is_active = true;
        $user = new User;
        $user->role = 'merchant';
        $user->store_id = $store->getKey();

        app(TenantContext::class)->setMerchantStore($user, $store);
    }
}
