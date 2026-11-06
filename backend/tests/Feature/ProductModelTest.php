<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
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

    private function createStore(): Store
    {
        return Store::create([
            'name' => 'Product Test Store',
            'slug' => 'product-store-'.bin2hex(random_bytes(5)),
        ]);
    }
}
