<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderItemModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());

        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
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
            $table->unsignedBigInteger('store_id');
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

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_code', 50)->unique();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('queue_id')->nullable()->unique();
            $table->unsignedBigInteger('nfc_card_id')->nullable();
            $table->string('customer_name', 100)->nullable();
            $table->unsignedInteger('total_items')->default(0);
            $table->decimal('subtotal', 12, 2)->default(0.00);
            $table->decimal('tax_amount', 12, 2)->default(0.00);
            $table->decimal('discount_amount', 12, 2)->default(0.00);
            $table->decimal('total_price', 12, 2)->default(0.00);
            $table->string('status')->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('product_name_snapshot', 150);
            $table->decimal('unit_price_snapshot', 12, 2)->default(0.00);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('subtotal', 12, 2)->default(0.00);
            $table->string('notes', 255)->nullable();
            $table->timestamps();
        });
    }

    public function test_order_and_product_relationships_work_in_both_directions(): void
    {
        [$order, $product, $item] = $this->createOrderItem();

        $this->assertInstanceOf(Order::class, $item->order);
        $this->assertSame($order->id, $item->order->id);
        $this->assertInstanceOf(Product::class, $item->product);
        $this->assertSame($product->id, $item->product->id);
        $this->assertTrue($order->items->contains('id', $item->id));
        $this->assertTrue($product->orderItems->contains('id', $item->id));
    }

    public function test_order_item_keeps_name_and_price_snapshots_after_product_changes(): void
    {
        [$order, $product, $item] = $this->createOrderItem();

        $product->update([
            'name' => 'Renamed product',
            'price' => '99.95',
        ]);

        $item->refresh();

        $this->assertSame('Product at order time', $item->product_name_snapshot);
        $this->assertSame('12.50', $item->unit_price_snapshot);
        $this->assertSame(2, $item->quantity);
        $this->assertSame('Order note', $item->notes);
        $this->assertSame('Renamed product', $product->fresh()->name);
        $this->assertSame('99.95', $product->fresh()->price);
    }

    public function test_soft_deleted_product_does_not_null_order_item_product_id(): void
    {
        [, $product, $item] = $this->createOrderItem();

        $product->delete();

        $this->assertSame($product->id, $item->fresh()->product_id);
        $this->assertNotNull(Product::withTrashed()->find($product->id)?->deleted_at);
        $this->assertTrue(DB::table('order_items')->where('id', $item->id)->where('product_id', $product->id)->exists());
    }

    public function test_snapshot_and_relation_fields_are_not_mass_assignable(): void
    {
        $item = new OrderItem([
            'order_id' => 11,
            'product_id' => 12,
            'product_name_snapshot' => 'Client product name',
            'unit_price_snapshot' => '999.99',
            'quantity' => 5,
            'subtotal' => '4999.95',
            'notes' => 'Customer note',
        ]);

        $this->assertNull($item->order_id);
        $this->assertNull($item->product_id);
        $this->assertNull($item->product_name_snapshot);
        $this->assertNull($item->unit_price_snapshot);
        $this->assertNull($item->subtotal);
        $this->assertSame(5, $item->quantity);
        $this->assertSame('Customer note', $item->notes);
    }

    public function test_order_item_casts_quantity_and_decimal_snapshots(): void
    {
        $item = new OrderItem;
        $item->quantity = '4';
        $item->unit_price_snapshot = '10.5';
        $item->subtotal = '42';

        $this->assertSame(4, $item->quantity);
        $this->assertSame('10.50', $item->unit_price_snapshot);
        $this->assertSame('42.00', $item->subtotal);
    }

    private function createOrderItem(): array
    {
        $store = Store::create([
            'name' => 'Order Item Test Store',
            'slug' => 'order-item-store-'.bin2hex(random_bytes(5)),
            'expires_at' => now()->addYear(),
        ]);
        $store->is_active = true;
        $user = new User;
        $user->role = 'merchant';
        $user->store_id = $store->getKey();
        app(TenantContext::class)->setMerchantStore($user, $store);

        $product = $store->products()->create([
            'name' => 'Product at order time',
            'price' => '12.50',
        ]);
        $order = $store->orders()->make([
            'customer_name' => 'Customer',
            'notes' => 'Order note',
        ]);
        $order->order_code = 'ORDER-'.bin2hex(random_bytes(5));
        $order->total_items = 2;
        $order->subtotal = '25.00';
        $order->tax_amount = '0.00';
        $order->discount_amount = '0.00';
        $order->total_price = '25.00';
        $order->status = 'pending';
        $order->save();

        $item = $order->items()->make([
            'quantity' => 2,
            'notes' => 'Order note',
        ]);
        $item->product_id = $product->id;
        $item->product_name_snapshot = $product->name;
        $item->unit_price_snapshot = $product->price;
        $item->subtotal = '25.00';
        $item->save();

        return [$order, $product, $item];
    }
}
