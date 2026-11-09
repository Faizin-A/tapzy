<?php

namespace Tests\Feature;

use App\Models\NfcCard;
use App\Models\Store;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithPrefixedMySqlDatabase;
use Tests\TestCase;
use Throwable;

class NfcCardAuditLogTest extends TestCase
{
    use InteractsWithPrefixedMySqlDatabase;

    private const ISOLATED_TABLES = [
        'audit_logs',
        'nfc_cards',
        'users',
        'stores',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPrefixedMySqlDatabase('nfc_audit');

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

    public function test_record_nfc_card_persists_entity_and_safe_snapshot(): void
    {
        $store = $this->createStore();
        $card = $this->createCard($store);
        $admin = $this->createSuperAdmin();
        $service = app(AuditLogService::class);

        $log = $service->recordNfcCard(
            AuditLogService::NFC_CARD_CREATED,
            $card,
            $admin,
            [],
            $service->nfcCardSnapshot($card),
            '203.0.113.10',
            'Test Agent/1.0',
        );

        $row = DB::table('audit_logs')->where('id', $log->id)->first();
        $snapshot = json_decode($row->new_values, true);

        $this->assertNotNull($row);
        $this->assertSame($store->id, (int) $row->store_id);
        $this->assertSame($admin->id, (int) $row->user_id);
        $this->assertSame(AuditLogService::NFC_CARD_CREATED, $row->action);
        $this->assertSame(NfcCard::class, $row->entity_type);
        $this->assertSame($card->id, (int) $row->entity_id);
        $this->assertSame('hardware-001', $snapshot['card_uid']);
        $this->assertSame('Meja 01', $snapshot['label']);
        $this->assertSame('table', $snapshot['type']);
        $this->assertArrayNotHasKey('public_token', $snapshot);
        $this->assertArrayNotHasKey('public_token_hash', $snapshot);
        $this->assertSame('203.0.113.10', $row->ip_address);
        $this->assertSame('Test Agent/1.0', $row->user_agent);
    }

    public function test_nfc_card_audit_payload_redacts_token_keys_recursively(): void
    {
        $store = $this->createStore();
        $card = $this->createCard($store);
        $admin = $this->createSuperAdmin();

        $log = app(AuditLogService::class)->recordNfcCard(
            AuditLogService::NFC_CARD_UPDATED,
            $card,
            $admin,
            ['public_token' => 'old-secret'],
            [
                'public_token' => 'new-secret',
                'public_token_hash' => hash('sha256', 'new-secret'),
                'nested' => ['nfc_token' => 'nested-secret'],
                'label' => 'Meja 02',
            ],
        );

        $row = DB::table('audit_logs')->where('id', $log->id)->first();
        $old = json_decode($row->old_values, true);
        $new = json_decode($row->new_values, true);

        $this->assertSame('[REDACTED]', $old['public_token']);
        $this->assertSame('[REDACTED]', $new['public_token']);
        $this->assertSame('[REDACTED]', $new['public_token_hash']);
        $this->assertSame('[REDACTED]', $new['nested']['nfc_token']);
        $this->assertSame('Meja 02', $new['label']);
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

    private function createCard(Store $store): NfcCard
    {
        return $store->nfcCards()->create([
            'card_uid' => 'hardware-001',
            'public_token_hash' => hash('sha256', 'public-token-001'),
            'label' => 'Meja 01',
            'type' => 'table',
            'is_active' => true,
        ]);
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
