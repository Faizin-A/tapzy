<?php

namespace Tests\Feature\Admin;

use App\Models\Store;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Support\InteractsWithPrefixedMySqlDatabase;
use Tests\TestCase;
use Throwable;

class NfcCardManagementTest extends TestCase
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

        $this->setUpPrefixedMySqlDatabase('nfc_admin');

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

    public function test_admin_can_create_a_pending_card_and_plaintext_token_is_returned_once(): void
    {
        $admin = $this->createSuperAdmin();
        $this->login($admin)->assertOk();
        $store = $this->createStore();

        $response = $this->withSpaOrigin()->postJson("/api/v1/admin/stores/{$store->id}/nfc-cards", [
            'card_uid' => 'hardware-001',
            'label' => 'Meja 01',
            'type' => 'table',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.store_id', $store->id)
            ->assertJsonPath('data.card_uid', 'hardware-001')
            ->assertJsonPath('data.label', 'Meja 01')
            ->assertJsonPath('data.type', 'table')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.qr_code.format', 'svg')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'store_id',
                    'card_uid',
                    'label',
                    'type',
                    'is_active',
                    'public_token',
                    'public_url',
                    'qr_code' => [
                        'format',
                        'svg',
                        'data_uri',
                    ],
                ],
            ]);

        $token = $response->json('data.public_token');
        $publicUrl = $response->json('data.public_url');
        $card = DB::table('nfc_cards')->where('id', $response->json('data.id'))->first();

        $this->assertSame(40, strlen($token));
        $this->assertSame(hash('sha256', $token), $card->public_token_hash);
        $this->assertSame(url('/api/v1/public/nfc/'.$token), $publicUrl);
        $this->assertNull($card->activated_at);
        $this->assertNull($card->deactivated_at);

        $svg = $response->json('data.qr_code.svg');
        $dataUri = $response->json('data.qr_code.data_uri');

        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($svg), 'QR SVG must be valid XML');
        $this->assertSame('svg', $dom->documentElement->nodeName);
        $this->assertSame('http://www.w3.org/2000/svg', $dom->documentElement->getAttribute('xmlns'));

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $dataUri);
        $decodedSvg = base64_decode(substr($dataUri, strlen('data:image/svg+xml;base64,')), true);
        $this->assertSame($svg, $decodedSvg, 'Decoded Data URI must match the exact SVG string');

        $audit = DB::table('audit_logs')->where('action', AuditLogService::NFC_CARD_CREATED)->first();
        $this->assertStringNotContainsString($token, $audit->new_values);
        $this->assertStringNotContainsString($card->public_token_hash, $audit->new_values);
        $this->assertStringNotContainsString('data:image/svg+xml', $audit->new_values);
        $this->assertStringNotContainsString('<svg', $audit->new_values);
    }

    public function test_admin_can_list_show_update_activate_and_delete_cards(): void
    {
        $admin = $this->createSuperAdmin();
        $this->login($admin)->assertOk();
        $store = $this->createStore();
        $card = $this->createCard($store);

        $listResponse = $this->withSpaOrigin()->getJson('/api/v1/admin/nfc-cards')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $card['id']);

        $listResponse->assertJsonMissingPath('data.0.public_token');
        $listResponse->assertJsonMissingPath('data.0.public_url');
        $listResponse->assertJsonMissingPath('data.0.qr_code');

        $showResponse = $this->withSpaOrigin()->getJson("/api/v1/admin/nfc-cards/{$card['id']}")
            ->assertOk()
            ->assertJsonPath('data.id', $card['id']);

        $showResponse->assertJsonMissingPath('data.public_token');
        $showResponse->assertJsonMissingPath('data.public_url');
        $showResponse->assertJsonMissingPath('data.qr_code');

        $this->withSpaOrigin()->patchJson("/api/v1/admin/nfc-cards/{$card['id']}", [
            'label' => 'Meja Baru',
            'type' => 'counter',
        ])->assertOk()
            ->assertJsonPath('data.label', 'Meja Baru')
            ->assertJsonPath('data.type', 'counter');

        $this->withSpaOrigin()->patchJson("/api/v1/admin/nfc-cards/{$card['id']}/status", [
            'is_active' => true,
        ])->assertOk()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.activated_at', fn ($value) => $value !== null)
            ->assertJsonPath('data.deactivated_at', null);

        $this->withSpaOrigin()->deleteJson("/api/v1/admin/nfc-cards/{$card['id']}")
            ->assertNoContent();

        $this->assertNotNull(DB::table('nfc_cards')->where('id', $card['id'])->value('deleted_at'));
        $this->withSpaOrigin()->getJson('/api/v1/admin/nfc-cards')->assertJsonCount(0, 'data');
        $this->withSpaOrigin()->getJson("/api/v1/admin/nfc-cards/{$card['id']}")->assertNotFound();
    }

    public function test_admin_create_rejects_duplicate_uid_second_store_card_and_client_controlled_status(): void
    {
        $admin = $this->createSuperAdmin();
        $this->login($admin)->assertOk();
        $store = $this->createStore();
        $otherStore = $this->createStore();
        $this->createCard($store, 'hardware-001');

        $this->withSpaOrigin()->postJson("/api/v1/admin/stores/{$store->id}/nfc-cards", [
            'card_uid' => 'hardware-002',
            'label' => 'Meja 02',
            'type' => 'table',
        ])->assertUnprocessable()->assertJsonValidationErrors('store_id');

        $this->withSpaOrigin()->postJson("/api/v1/admin/stores/{$otherStore->id}/nfc-cards", [
            'card_uid' => 'hardware-001',
            'label' => 'Meja Lain',
            'type' => 'table',
        ])->assertUnprocessable()->assertJsonValidationErrors('card_uid');

        $this->withSpaOrigin()->postJson("/api/v1/admin/stores/{$otherStore->id}/nfc-cards", [
            'card_uid' => 'hardware-003',
            'label' => 'Meja Lain',
            'type' => 'table',
            'is_active' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('is_active');
    }

    public function test_admin_update_prohibits_card_uid_store_and_status_changes(): void
    {
        $admin = $this->createSuperAdmin();
        $this->login($admin)->assertOk();
        $store = $this->createStore();
        $card = $this->createCard($store);

        $this->withSpaOrigin()->patchJson("/api/v1/admin/nfc-cards/{$card['id']}", [
            'card_uid' => 'changed',
        ])->assertUnprocessable()->assertJsonValidationErrors('card_uid');

        $this->withSpaOrigin()->patchJson("/api/v1/admin/nfc-cards/{$card['id']}", [
            'store_id' => 999,
        ])->assertUnprocessable()->assertJsonValidationErrors('store_id');

        $this->withSpaOrigin()->patchJson("/api/v1/admin/nfc-cards/{$card['id']}", [
            'is_active' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('is_active');
    }

    public function test_activation_rejects_a_non_operational_store(): void
    {
        $admin = $this->createSuperAdmin();
        $this->login($admin)->assertOk();
        $store = $this->createStore(active: false);
        $card = $this->createCard($store);

        $this->withSpaOrigin()->patchJson("/api/v1/admin/nfc-cards/{$card['id']}/status", [
            'is_active' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('store_id');
    }

    public function test_merchant_cannot_access_admin_card_endpoints(): void
    {
        $store = $this->createStore();
        $merchant = $this->createMerchant($store);
        $this->login($merchant)->assertOk();

        $this->withSpaOrigin()->getJson('/api/v1/admin/nfc-cards')->assertForbidden();
        $this->withSpaOrigin()->postJson("/api/v1/admin/stores/{$store->id}/nfc-cards", [
            'card_uid' => 'hardware-001',
            'label' => 'Meja 01',
            'type' => 'table',
        ])->assertForbidden();
    }

    public function test_card_creation_for_soft_deleted_store_returns_not_found(): void
    {
        $admin = $this->createSuperAdmin();
        $this->login($admin)->assertOk();
        $store = $this->createStore();
        $store->delete();

        $this->withSpaOrigin()->postJson("/api/v1/admin/stores/{$store->id}/nfc-cards", [
            'card_uid' => 'hardware-001',
            'label' => 'Meja 01',
            'type' => 'table',
        ])->assertNotFound();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->withSpaOrigin()->getJson('/api/v1/admin/nfc-cards')->assertUnauthorized();
    }

    private function createCard(Store $store, string $cardUid = 'hardware-001'): array
    {
        $response = $this->withSpaOrigin()->postJson("/api/v1/admin/stores/{$store->id}/nfc-cards", [
            'card_uid' => $cardUid,
            'label' => 'Meja 01',
            'type' => 'table',
        ])->assertCreated();

        return $response->json('data');
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

    private function createStore(bool $active = true): Store
    {
        return Store::create([
            'name' => 'Store '.bin2hex(random_bytes(4)),
            'slug' => 'store-'.bin2hex(random_bytes(6)),
            'is_active' => $active,
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

    private function createSuperAdmin(): User
    {
        $user = new User([
            'name' => 'Super Admin',
            'email' => 'admin-'.bin2hex(random_bytes(6)).'@example.test',
            'password' => 'correct-password',
        ]);
        $user->role = 'super_admin';
        $user->store_id = null;
        $user->save();

        return $user;
    }
}
