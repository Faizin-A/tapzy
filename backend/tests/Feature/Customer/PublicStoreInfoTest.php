<?php

namespace Tests\Feature\Customer;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithPrefixedMySqlDatabase;
use Tests\TestCase;
use Throwable;

class PublicStoreInfoTest extends TestCase
{
    use InteractsWithPrefixedMySqlDatabase;

    private const ISOLATED_TABLES = [
        'nfc_cards',
        'stores',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPrefixedMySqlDatabase('cust_store_info');

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

    public function test_returns_public_store_profile_for_valid_token(): void
    {
        $store = $this->createStore();
        $token = $this->token();
        $this->insertCard($store->id, $token);

        $response = $this->getJson("/api/v1/public/stores/{$token}");

        $response->assertOk()
            ->assertJsonPath('data.name', $store->name)
            ->assertJsonPath('data.description', 'Deskripsi toko')
            ->assertJsonPath('data.phone', '08123456789')
            ->assertJsonPath('data.address', 'Jl. Contoh No. 1')
            ->assertJsonPath('data.google_maps_url', 'https://maps.example.test/store')
            ->assertJsonPath('data.avg_service_time', 12)
            ->assertJsonPath('data.queue_prefix', 'B')
            ->assertJsonPath('data.ordering_mode', 'queue_and_order')
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.store_id')
            ->assertJsonMissingPath('data.is_active')
            ->assertJsonMissingPath('data.expires_at')
            ->assertJsonMissingPath('data.deleted_at')
            ->assertJsonMissingPath('data.public_token_hash')
            ->assertJsonMissingPath('data.card_uid');
    }

    public function test_does_not_update_last_used_at(): void
    {
        $store = $this->createStore();
        $token = $this->token();
        $cardId = $this->insertCard($store->id, $token);

        $this->getJson("/api/v1/public/stores/{$token}")->assertOk();
        $this->getJson("/api/v1/public/stores/{$token}")->assertOk();

        $lastUsedAt = DB::table('nfc_cards')->where('id', $cardId)->value('last_used_at');

        $this->assertNull($lastUsedAt);
    }

    public function test_returns_queue_only_ordering_mode(): void
    {
        $store = $this->createStore(orderingMode: 'queue_only');
        $token = $this->token();
        $this->insertCard($store->id, $token);

        $this->getJson("/api/v1/public/stores/{$token}")
            ->assertOk()
            ->assertJsonPath('data.ordering_mode', 'queue_only');
    }

    public function test_returns_not_found_for_malformed_or_unknown_token(): void
    {
        $this->getJson('/api/v1/public/stores/too-short')->assertNotFound();
        $this->getJson('/api/v1/public/stores/'.str_repeat('!', 40))->assertNotFound();
        $this->getJson('/api/v1/public/stores/'.str_repeat('A', 39))->assertNotFound();
        $this->getJson('/api/v1/public/stores/'.str_repeat('Z', 40))->assertNotFound();
    }

    public function test_returns_not_found_for_inactive_or_deleted_card(): void
    {
        $store = $this->createStore();
        $inactiveToken = $this->token();
        $deletedToken = $this->token();

        $this->insertCard($store->id, $inactiveToken, active: false);
        $this->insertCard($store->id, $deletedToken, deleted: true);

        $this->getJson("/api/v1/public/stores/{$inactiveToken}")->assertNotFound();
        $this->getJson("/api/v1/public/stores/{$deletedToken}")->assertNotFound();
    }

    public function test_returns_not_found_for_inactive_expired_or_soft_deleted_store(): void
    {
        $inactiveStore = $this->createStore(isActive: false);
        $expiredStore = $this->createStore(expiresAt: now()->subHour());
        $deletedStore = $this->createStore();

        $inactiveToken = $this->token();
        $expiredToken = $this->token();
        $deletedToken = $this->token();

        $this->insertCard($inactiveStore->id, $inactiveToken);
        $this->insertCard($expiredStore->id, $expiredToken);
        $this->insertCard($deletedStore->id, $deletedToken);

        DB::table('stores')->where('id', $deletedStore->id)->update(['deleted_at' => now()]);

        $this->getJson("/api/v1/public/stores/{$inactiveToken}")->assertNotFound();
        $this->getJson("/api/v1/public/stores/{$expiredToken}")->assertNotFound();
        $this->getJson("/api/v1/public/stores/{$deletedToken}")->assertNotFound();
    }

    public function test_rate_limiting_enforced_per_token_and_ip(): void
    {
        $store = $this->createStore();
        $token = $this->token();
        $this->insertCard($store->id, $token);

        for ($i = 0; $i < 60; $i++) {
            $this->getJson("/api/v1/public/stores/{$token}")->assertOk();
        }

        $this->getJson("/api/v1/public/stores/{$token}")->assertStatus(429);
    }

    private function createStore(
        bool $isActive = true,
        mixed $expiresAt = null,
        string $orderingMode = 'queue_and_order',
    ): object {
        $id = DB::table('stores')->insertGetId([
            'name' => 'Store '.bin2hex(random_bytes(4)),
            'slug' => 'store-'.bin2hex(random_bytes(6)),
            'description' => 'Deskripsi toko',
            'phone' => '08123456789',
            'address' => 'Jl. Contoh No. 1',
            'google_maps_url' => 'https://maps.example.test/store',
            'is_active' => $isActive,
            'avg_service_time' => 12,
            'queue_prefix' => 'B',
            'ordering_mode' => $orderingMode,
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

    private function token(): string
    {
        return bin2hex(random_bytes(20));
    }
}
