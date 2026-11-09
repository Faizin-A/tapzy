<?php

namespace Tests\Feature;

use App\Models\NfcCard;
use App\Models\QueueSession;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithPrefixedMySqlDatabase;
use Tests\TestCase;
use Throwable;

class NfcCardQueueSessionModelTest extends TestCase
{
    use InteractsWithPrefixedMySqlDatabase;

    private const ISOLATED_TABLES = [
        'nfc_cards',
        'queue_sessions',
        'stores',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPrefixedMySqlDatabase('nfc_model');

        try {
            foreach (self::ISOLATED_TABLES as $table) {
                Schema::dropIfExists($table);
            }

            Schema::create('stores', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->string('slug', 150)->unique();
                $table->boolean('is_active')->default(true);
                $table->dateTime('expires_at');
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
                $table->unique(['store_id', 'card_uid'], 'uq_store_card_uid');
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
                $table->unique(['store_id', 'session_code'], 'uq_store_session_code');
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

    public function test_store_and_child_model_relationships_work(): void
    {
        $store = $this->createStore();
        $card = $store->nfcCards()->create([
            'card_uid' => 'hardware-001',
            'public_token_hash' => hash('sha256', 'public-token-001'),
            'label' => 'Counter',
        ]);
        $session = $store->queueSessions()->create([
            'session_code' => 'QS-TEST-001',
            'session_date' => '2026-11-06',
            'opened_at' => now(),
        ]);

        $this->assertInstanceOf(Store::class, $card->store);
        $this->assertSame($store->id, $card->store->id);
        $this->assertInstanceOf(Store::class, $session->store);
        $this->assertSame($store->id, $session->store->id);
        $this->assertTrue($store->nfcCards->contains('id', $card->id));
        $this->assertTrue($store->queueSessions->contains('id', $session->id));
    }

    public function test_nfc_card_soft_delete_keeps_card_available_with_trashed(): void
    {
        $store = $this->createStore();
        $card = $store->nfcCards()->create([
            'card_uid' => 'hardware-002',
            'public_token_hash' => hash('sha256', 'public-token-002'),
            'label' => 'Table 2',
        ]);

        $card->delete();

        $this->assertNull(NfcCard::find($card->id));
        $this->assertNotNull(NfcCard::withTrashed()->find($card->id)?->deleted_at);
        $this->assertFalse(method_exists(QueueSession::class, 'bootSoftDeletes'));
    }

    public function test_store_id_cannot_be_set_by_normal_mass_assignment(): void
    {
        $card = new NfcCard(['store_id' => 17, 'card_uid' => 'hardware-003']);
        $session = new QueueSession(['store_id' => 17, 'session_code' => 'QS-TEST-003']);

        $this->assertNull($card->store_id);
        $this->assertNull($session->store_id);
    }

    public function test_nfc_card_hides_sensitive_attributes_and_checks_active_state(): void
    {
        $store = $this->createStore();
        $card = $store->nfcCards()->create([
            'card_uid' => 'hardware-004',
            'public_token_hash' => hash('sha256', 'public-token-004'),
            'label' => 'Counter',
            'is_active' => true,
        ]);

        $serialized = $card->toArray();

        $this->assertArrayNotHasKey('card_uid', $serialized);
        $this->assertArrayNotHasKey('public_token_hash', $serialized);
        $this->assertTrue($card->isOperational());

        $card->is_active = false;
        $card->save();

        $this->assertFalse($card->refresh()->isOperational());
    }

    public function test_nfc_card_requires_an_operational_store(): void
    {
        $store = $this->createStore();
        $card = $store->nfcCards()->create([
            'card_uid' => 'hardware-005',
            'public_token_hash' => hash('sha256', 'public-token-005'),
            'label' => 'Counter',
            'is_active' => true,
        ]);

        $store->is_active = false;
        $store->save();
        $this->assertFalse($card->refresh()->isOperational());

        $store->is_active = true;
        $store->expires_at = now()->subMinute();
        $store->save();
        $this->assertFalse($card->refresh()->isOperational());

        $store->expires_at = now()->addYear();
        $store->save();
        $this->assertTrue($card->refresh()->isOperational());

        $store->delete();
        $this->assertFalse($card->refresh()->isOperational());

        $card->delete();
        $this->assertFalse($card->isOperational());
    }

    private function createStore(): Store
    {
        $store = Store::create([
            'name' => 'Test Store',
            'slug' => 'store-'.uniqid(),
            'expires_at' => now()->addYear(),
        ]);
        $store->is_active = true;

        $user = new User;
        $user->role = 'merchant';
        $user->store_id = $store->getKey();
        app(TenantContext::class)->setMerchantStore($user, $store);

        return $store;
    }
}
