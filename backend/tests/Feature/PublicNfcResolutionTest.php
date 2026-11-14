<?php

namespace Tests\Feature;

use App\Models\Store;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithPrefixedMySqlDatabase;
use Tests\TestCase;
use Throwable;

class PublicNfcResolutionTest extends TestCase
{
    use InteractsWithPrefixedMySqlDatabase;

    private const ISOLATED_TABLES = [
        'nfc_cards',
        'stores',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPrefixedMySqlDatabase('nfc_public');

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

    public function test_public_resolution_returns_public_store_data_and_updates_last_used_at(): void
    {
        $store = $this->createStore();
        $token = $this->token();
        $cardId = $this->insertCard($store, $token, label: 'Meja 01');

        $response = $this->getJson('/api/v1/public/nfc/'.$token);

        $response->assertOk()
            ->assertJsonPath('data.store.slug', $store->slug)
            ->assertJsonPath('data.store.name', $store->name)
            ->assertJsonPath('data.card.label', 'Meja 01')
            ->assertJsonPath('data.card.type', 'table');

        $response->assertJsonMissingPath('data.store.id');
        $response->assertJsonMissingPath('data.card.id');
        $response->assertJsonMissingPath('data.card.card_uid');
        $response->assertJsonMissingPath('data.card.public_token');
        $response->assertJsonMissingPath('data.card.public_token_hash');

        $this->assertNotNull(DB::table('nfc_cards')->where('id', $cardId)->value('last_used_at'));
    }

    public function test_browser_request_redirects_to_customer_shell_and_updates_last_used_at(): void
    {
        $store = $this->createStore();
        $token = $this->token();
        $cardId = $this->insertCard($store, $token);

        $response = $this->get('/api/v1/public/nfc/'.$token, ['Accept' => 'text/html']);

        $response->assertRedirect(route('customer.store', ['token' => $token]));
        $this->assertNotNull(DB::table('nfc_cards')->where('id', $cardId)->value('last_used_at'));
    }

    public function test_invalid_token_returns_generic_not_found_without_touching_last_used_at(): void
    {
        $store = $this->createStore();
        $token = $this->token();
        $cardId = $this->insertCard($store, $token);

        $this->getJson('/api/v1/public/nfc/'.str_repeat('A', 40))
            ->assertNotFound();

        $this->assertNull(DB::table('nfc_cards')->where('id', $cardId)->value('last_used_at'));
    }

    public function test_inactive_or_soft_deleted_card_returns_not_found(): void
    {
        $store = $this->createStore();
        $inactiveToken = $this->token();
        $deletedToken = $this->token();
        $this->insertCard($store, $inactiveToken, active: false);

        $otherStore = $this->createStore();
        $this->insertCard($otherStore, $deletedToken, deleted: true);

        $this->getJson('/api/v1/public/nfc/'.$inactiveToken)->assertNotFound();
        $this->getJson('/api/v1/public/nfc/'.$deletedToken)->assertNotFound();
    }

    public function test_non_operational_store_returns_not_found(): void
    {
        $inactiveStore = $this->createStore();
        $inactiveToken = $this->token();
        $this->insertCard($inactiveStore, $inactiveToken);
        $inactiveStore->is_active = false;
        $inactiveStore->save();

        $expiredStore = $this->createStore();
        $expiredToken = $this->token();
        $this->insertCard($expiredStore, $expiredToken);
        $expiredStore->expires_at = now()->subMinute();
        $expiredStore->save();

        $deletedStore = $this->createStore();
        $deletedToken = $this->token();
        $this->insertCard($deletedStore, $deletedToken);
        $deletedStore->delete();

        $this->getJson('/api/v1/public/nfc/'.$inactiveToken)->assertNotFound();
        $this->getJson('/api/v1/public/nfc/'.$expiredToken)->assertNotFound();
        $this->getJson('/api/v1/public/nfc/'.$deletedToken)->assertNotFound();
    }

    public function test_public_resolution_is_rate_limited_by_token_hash(): void
    {
        $token = str_repeat('B', 40);

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->getJson('/api/v1/public/nfc/'.$token)->assertNotFound();
        }

        $this->getJson('/api/v1/public/nfc/'.$token)->assertStatus(429);
    }

    private function createStore(): Store
    {
        return Store::create([
            'name' => 'Store '.bin2hex(random_bytes(4)),
            'slug' => 'store-'.bin2hex(random_bytes(6)),
            'description' => 'Deskripsi toko',
            'phone' => '08123456789',
            'address' => 'Jl. Contoh',
            'is_active' => true,
            'expires_at' => now()->addYear(),
        ]);
    }

    private function insertCard(
        Store $store,
        string $token,
        string $label = 'Meja 01',
        bool $active = true,
        bool $deleted = false,
    ): int {
        return DB::table('nfc_cards')->insertGetId([
            'store_id' => $store->id,
            'card_uid' => 'hardware-'.bin2hex(random_bytes(6)),
            'public_token_hash' => hash('sha256', $token),
            'label' => $label,
            'type' => 'table',
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $deleted ? now() : null,
        ]);
    }

    private function token(): string
    {
        return bin2hex(random_bytes(20));
    }
}
