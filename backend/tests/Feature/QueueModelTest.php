<?php

namespace Tests\Feature;

use App\Models\NfcCard;
use App\Models\Queue;
use App\Models\QueueSession;
use App\Models\Store;
use Carbon\CarbonInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QueueModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());

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
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('card_uid', 100);
            $table->char('public_token_hash', 64)->unique();
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
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('session_code', 50);
            $table->date('session_date');
            $table->unsignedInteger('last_queue_number')->default(0);
            $table->string('status')->default('active');
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
    }

    public function test_queue_relationships_work_in_both_directions(): void
    {
        [$store, $session, $card] = $this->createQueueParents();
        $queue = $this->createQueue($store, $session, $card);

        $this->assertInstanceOf(Store::class, $queue->store);
        $this->assertSame($store->id, $queue->store->id);
        $this->assertInstanceOf(QueueSession::class, $queue->queueSession);
        $this->assertSame($session->id, $queue->queueSession->id);
        $this->assertInstanceOf(NfcCard::class, $queue->nfcCard);
        $this->assertSame($card->id, $queue->nfcCard->id);
        $this->assertTrue($store->queues->contains('id', $queue->id));
        $this->assertTrue($session->queues->contains('id', $queue->id));
        $this->assertTrue($card->queues->contains('id', $queue->id));
    }

    public function test_queue_number_and_status_timestamps_are_cast(): void
    {
        [$store, $session, $card] = $this->createQueueParents();
        $queue = $this->createQueue($store, $session, $card);

        $this->assertIsInt($queue->queue_number);
        $this->assertInstanceOf(CarbonInterface::class, $queue->called_at);
        $this->assertInstanceOf(CarbonInterface::class, $queue->served_at);
        $this->assertInstanceOf(CarbonInterface::class, $queue->completed_at);
        $this->assertInstanceOf(CarbonInterface::class, $queue->cancelled_at);
    }

    public function test_mass_assignment_excludes_backend_owned_queue_fields(): void
    {
        $queue = new Queue([
            'store_id' => 11,
            'queue_session_id' => 12,
            'nfc_card_id' => 13,
            'queue_number' => 14,
            'formatted_number' => 'A-014',
            'source' => 'manual',
            'access_token_hash' => str_repeat('a', 64),
            'status' => 'completed',
            'customer_name' => 'Customer',
        ]);

        $this->assertNull($queue->store_id);
        $this->assertNull($queue->queue_session_id);
        $this->assertNull($queue->nfc_card_id);
        $this->assertNull($queue->queue_number);
        $this->assertNull($queue->formatted_number);
        $this->assertNull($queue->source);
        $this->assertNull($queue->access_token_hash);
        $this->assertNull($queue->status);
        $this->assertSame('Customer', $queue->customer_name);
    }

    public function test_access_token_hash_is_hidden_when_queue_is_serialized(): void
    {
        $queue = new Queue;
        $queue->access_token_hash = str_repeat('b', 64);

        $this->assertArrayNotHasKey('access_token_hash', $queue->toArray());
    }

    private function createQueueParents(): array
    {
        $store = Store::create([
            'name' => 'Test Store',
            'slug' => 'store-'.uniqid(),
        ]);
        $session = $store->queueSessions()->create([
            'session_code' => 'QS-'.uniqid(),
            'session_date' => '2026-11-06',
            'opened_at' => now(),
        ]);
        $card = $store->nfcCards()->create([
            'card_uid' => 'uid-'.uniqid(),
            'public_token_hash' => hash('sha256', uniqid('', true)),
            'label' => 'Test point',
        ]);

        return [$store, $session, $card];
    }

    private function createQueue(Store $store, QueueSession $session, NfcCard $card): Queue
    {
        $queue = $session->queues()->make([
            'customer_name' => 'Customer',
            'customer_phone' => '08123456789',
            'notes' => 'No onions',
        ]);
        $queue->store_id = $store->id;
        $queue->nfc_card_id = $card->id;
        $queue->queue_number = 1;
        $queue->formatted_number = 'A-001';
        $queue->source = 'nfc';
        $queue->access_token_hash = hash('sha256', 'customer-token');
        $queue->status = 'in_service';
        $queue->called_at = now();
        $queue->served_at = now();
        $queue->completed_at = now();
        $queue->cancelled_at = now();
        $queue->save();

        return $queue;
    }
}
