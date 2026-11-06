<?php

namespace Tests\Feature;

use App\Models\NfcCard;
use App\Models\Order;
use App\Models\Queue;
use App\Models\Store;
use Carbon\CarbonInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());

        Schema::dropIfExists('orders');
        Schema::dropIfExists('queues');
        Schema::dropIfExists('queue_sessions');
        Schema::dropIfExists('nfc_cards');
        Schema::dropIfExists('stores');

        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 150)->unique();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('nfc_cards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('card_uid', 100);
            $table->char('public_token_hash', 64);
            $table->string('label', 100);
            $table->string('type')->default('table');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('queue_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('session_code', 50);
            $table->date('session_date');
            $table->unsignedInteger('last_queue_number')->default(0);
            $table->string('status')->default('active');
            $table->unsignedBigInteger('active_store_id')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('queues', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('queue_session_id');
            $table->unsignedBigInteger('nfc_card_id')->nullable();
            $table->unsignedInteger('queue_number');
            $table->string('formatted_number', 20);
            $table->string('customer_name', 100)->nullable();
            $table->string('customer_phone', 30)->nullable();
            $table->string('source')->default('nfc');
            $table->char('access_token_hash', 64);
            $table->string('status')->default('waiting');
            $table->timestamp('called_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
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
    }

    public function test_order_relationships_work_in_both_directions(): void
    {
        [$store, $queue, $card] = $this->createOrderParents();
        $order = $this->createOrder($store, 'ORDER-RELATION-1', $queue, $card);

        $this->assertInstanceOf(Store::class, $order->store);
        $this->assertSame($store->id, $order->store->id);
        $this->assertInstanceOf(Queue::class, $order->queue);
        $this->assertSame($queue->id, $order->queue->id);
        $this->assertInstanceOf(NfcCard::class, $order->nfcCard);
        $this->assertSame($card->id, $order->nfcCard->id);
        $this->assertTrue($store->orders->contains('id', $order->id));
        $this->assertSame($order->id, $queue->order->id);
        $this->assertTrue($card->orders->contains('id', $order->id));
    }

    public function test_direct_order_can_have_null_queue_and_card(): void
    {
        $store = $this->createStore();
        $order = $this->createOrder($store, 'ORDER-DIRECT-1');

        $this->assertNull($order->queue_id);
        $this->assertNull($order->nfc_card_id);
        $this->assertTrue($store->orders->contains('id', $order->id));
        $this->assertNull($order->queue);
        $this->assertNull($order->nfcCard);
    }

    public function test_amounts_counts_and_completion_timestamp_are_cast(): void
    {
        $order = new Order([
            'customer_name' => 'Customer',
            'notes' => 'Leave at counter',
        ]);
        $order->total_items = '3';
        $order->subtotal = '123.4';
        $order->tax_amount = '10';
        $order->discount_amount = '5.5';
        $order->total_price = '127.9';
        $order->completed_at = '2026-11-06 10:30:00';

        $this->assertSame(3, $order->total_items);
        $this->assertSame('123.40', $order->subtotal);
        $this->assertSame('10.00', $order->tax_amount);
        $this->assertSame('5.50', $order->discount_amount);
        $this->assertSame('127.90', $order->total_price);
        $this->assertInstanceOf(CarbonInterface::class, $order->completed_at);
    }

    public function test_order_soft_delete_preserves_row_and_with_trashed_retrieves_it(): void
    {
        $store = $this->createStore();
        $order = $this->createOrder($store, 'ORDER-SOFT-DELETE-1');

        $order->delete();

        $this->assertNull(Order::find($order->id));
        $this->assertNotNull(Order::withTrashed()->find($order->id)?->deleted_at);
        $this->assertTrue(DB::table('orders')->where('id', $order->id)->whereNotNull('deleted_at')->exists());
    }

    public function test_mass_assignment_excludes_backend_owned_order_fields(): void
    {
        $order = new Order([
            'store_id' => 11,
            'queue_id' => 12,
            'nfc_card_id' => 13,
            'order_code' => 'CLIENT-ORDER-CODE',
            'total_items' => 2,
            'subtotal' => '100.00',
            'tax_amount' => '10.00',
            'discount_amount' => '1.00',
            'total_price' => '109.00',
            'status' => 'completed',
            'customer_name' => 'Customer',
        ]);

        foreach ([
            'store_id', 'queue_id', 'nfc_card_id', 'order_code', 'total_items',
            'subtotal', 'tax_amount', 'discount_amount', 'total_price', 'status',
        ] as $backendOwnedField) {
            $this->assertNull($order->getAttribute($backendOwnedField), $backendOwnedField.' must not be mass assignable.');
        }

        $this->assertSame('Customer', $order->customer_name);
    }

    private function createOrderParents(): array
    {
        $store = $this->createStore();
        $session = $store->queueSessions()->create([
            'session_code' => 'ORDER-SESSION-'.bin2hex(random_bytes(4)),
            'session_date' => '2026-11-06',
            'opened_at' => now(),
        ]);
        $card = $store->nfcCards()->create([
            'card_uid' => 'order-card-'.bin2hex(random_bytes(4)),
            'public_token_hash' => hash('sha256', random_bytes(32)),
            'label' => 'Order counter',
        ]);
        $queue = $session->queues()->make([
            'customer_name' => 'Customer',
            'customer_phone' => '08123456789',
            'notes' => null,
        ]);
        $queue->store_id = $store->id;
        $queue->nfc_card_id = $card->id;
        $queue->queue_number = 1;
        $queue->formatted_number = 'A-001';
        $queue->source = 'nfc';
        $queue->access_token_hash = hash('sha256', 'order-queue-token');
        $queue->save();

        return [$store, $queue, $card];
    }

    private function createStore(): Store
    {
        return Store::create([
            'name' => 'Order Test Store',
            'slug' => 'order-store-'.bin2hex(random_bytes(5)),
        ]);
    }

    private function createOrder(Store $store, string $code, ?Queue $queue = null, ?NfcCard $card = null): Order
    {
        $order = new Order([
            'customer_name' => 'Customer',
            'notes' => 'Test notes',
        ]);
        $order->store_id = $store->id;
        $order->queue_id = $queue?->id;
        $order->nfc_card_id = $card?->id;
        $order->order_code = $code;
        $order->total_items = 0;
        $order->subtotal = '0.00';
        $order->tax_amount = '0.00';
        $order->discount_amount = '0.00';
        $order->total_price = '0.00';
        $order->status = 'pending';
        $order->save();

        return $order;
    }
}
