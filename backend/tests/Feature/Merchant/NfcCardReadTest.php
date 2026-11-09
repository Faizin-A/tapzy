<?php

namespace Tests\Feature\Merchant;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Support\InteractsWithPrefixedMySqlDatabase;
use Tests\TestCase;
use Throwable;

class NfcCardReadTest extends TestCase
{
    use InteractsWithPrefixedMySqlDatabase;

    private const TEST_APP_KEY = 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=';

    private const ISOLATED_TABLES = [
        'audit_logs',
        'nfc_cards',
        'personal_access_tokens',
        'sessions',
        'users',
        'stores',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => self::TEST_APP_KEY]);
        config(['session.driver' => 'array']);
        config(['sanctum.stateful' => ['localhost:3000']]);

        $this->setUpPrefixedMySqlDatabase('nfc_merchant');

        try {
            foreach (self::ISOLATED_TABLES as $table) {
                Schema::dropIfExists($table);
            }

            Schema::create('stores', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 150);
                $table->string('slug', 150)->unique();
                $table->boolean('is_active')->default(true);
                $table->dateTime('expires_at');
                $table->timestamps();
                $table->softDeletes();
            });

            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 150);
                $table->string('email')->unique();
                $table->string('password');
                $table->rememberToken();
                $table->string('role');
                $table->unsignedBigInteger('store_id')->nullable();
                $table->unsignedBigInteger('session_generation')->default(1);
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

            Schema::create('audit_logs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('store_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('action', 100);
                $table->string('entity_type', 100)->nullable();
                $table->unsignedBigInteger('entity_id')->nullable();
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });

            Schema::create('sessions', function (Blueprint $table): void {
                $table->string('id')->primary();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });

            Schema::create('personal_access_tokens', function (Blueprint $table): void {
                $table->id();
                $table->morphs('tokenable', 'pat_tokenable_index');
                $table->text('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
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

    public function test_merchant_lists_and_shows_only_own_active_cards_without_sensitive_fields(): void
    {
        $store = $this->createStore();
        $otherStore = $this->createStore();
        $merchant = $this->createMerchant($store);
        $ownCardId = $this->insertCard($store, 'hardware-own', 'Meja Sendiri');
        $this->insertCard($otherStore, 'hardware-other', 'Meja Lain');
        $deletedCardId = $this->insertCard($store, 'hardware-deleted', 'Meja Dihapus', deleted: true);

        $this->login($merchant)->assertOk();

        $list = $this->withSpaOrigin()->getJson('/api/v1/merchant/nfc-cards')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownCardId)
            ->assertJsonPath('data.0.label', 'Meja Sendiri');

        $list->assertJsonMissingPath('data.0.card_uid');
        $list->assertJsonMissingPath('data.0.public_token');
        $list->assertJsonMissingPath('data.0.public_token_hash');
        $list->assertJsonMissingPath('data.0.public_url');
        $list->assertJsonMissingPath('data.0.qr_code');
        $list->assertJsonMissingPath('data.0.store_id');

        $this->withSpaOrigin()->getJson("/api/v1/merchant/nfc-cards/{$ownCardId}")
            ->assertOk()
            ->assertJsonPath('data.id', $ownCardId)
            ->assertJsonMissingPath('data.card_uid')
            ->assertJsonMissingPath('data.public_token')
            ->assertJsonMissingPath('data.public_token_hash')
            ->assertJsonMissingPath('data.public_url')
            ->assertJsonMissingPath('data.qr_code');

        $this->withSpaOrigin()->getJson("/api/v1/merchant/nfc-cards/{$deletedCardId}")
            ->assertNotFound();
    }

    public function test_merchant_cannot_read_another_stores_card(): void
    {
        $store = $this->createStore();
        $otherStore = $this->createStore();
        $merchant = $this->createMerchant($store);
        $otherCardId = $this->insertCard($otherStore, 'hardware-other', 'Meja Lain');

        $this->login($merchant)->assertOk();

        $this->withSpaOrigin()->getJson("/api/v1/merchant/nfc-cards/{$otherCardId}")
            ->assertNotFound();
    }

    public function test_merchant_cannot_filter_by_store_id_or_write_cards(): void
    {
        $store = $this->createStore();
        $merchant = $this->createMerchant($store);
        $this->login($merchant)->assertOk();

        $this->withSpaOrigin()->getJson('/api/v1/merchant/nfc-cards?store_id='.$store->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('store_id');

        $this->withSpaOrigin()->postJson('/api/v1/merchant/nfc-cards', [
            'card_uid' => 'hardware-new',
            'label' => 'Meja Baru',
            'type' => 'table',
        ])->assertMethodNotAllowed();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->withSpaOrigin()->getJson('/api/v1/merchant/nfc-cards')->assertUnauthorized();
    }

    private function insertCard(Store $store, string $uid, string $label, bool $deleted = false): int
    {
        return DB::table('nfc_cards')->insertGetId([
            'store_id' => $store->id,
            'card_uid' => $uid,
            'public_token_hash' => hash('sha256', 'token-'.$uid),
            'label' => $label,
            'type' => 'table',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $deleted ? now() : null,
        ]);
    }

    private function login(User $user): TestResponse
    {
        return $this->withSpaOrigin()->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);
    }

    private function withSpaOrigin(): static
    {
        return $this->withHeaders([
            'Origin' => 'http://localhost:3000',
            'Referer' => 'http://localhost:3000/',
        ]);
    }

    private function createStore(): Store
    {
        return Store::create([
            'name' => 'Store '.bin2hex(random_bytes(4)),
            'slug' => 'store-'.bin2hex(random_bytes(6)),
            'is_active' => true,
            'expires_at' => now()->addYear(),
        ]);
    }

    private function createMerchant(Store $store): User
    {
        $user = new User([
            'name' => 'Merchant',
            'email' => 'merchant-'.bin2hex(random_bytes(6)).'@example.test',
            'password' => 'correct-password',
        ]);
        $user->role = 'merchant';
        $user->store_id = $store->getKey();
        $user->save();

        return $user;
    }
}
