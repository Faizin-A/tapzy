<?php

namespace Tests\Feature;

use App\Models\NfcCard;
use App\Models\Store;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\NfcCard\NfcCardManagementService;
use App\Support\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Support\InteractsWithPrefixedMySqlDatabase;
use Tests\TestCase;
use Throwable;

class NfcCardManagementServiceTest extends TestCase
{
    use InteractsWithPrefixedMySqlDatabase;

    private const ISOLATED_TABLES = [
        'audit_logs',
        'nfc_cards',
        'users',
        'stores',
    ];

    private NfcCardManagementService $service;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPrefixedMySqlDatabase('nfc_svc');

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

            $this->admin = $this->createSuperAdmin();
            app(TenantContext::class)->enablePlatformAccess($this->admin);
            $this->service = app(NfcCardManagementService::class);
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

    public function test_create_stores_only_hash_and_returns_plaintext_token_once(): void
    {
        $store = $this->createStore();

        $result = $this->service->create(
            $store,
            ['card_uid' => 'hardware-001', 'label' => 'Meja 01', 'type' => 'table'],
            $this->admin,
            '203.0.113.10',
            'Test Agent/1.0',
        );

        $card = $result['card'];
        $row = DB::table('nfc_cards')->where('id', $card->id)->first();

        $this->assertFalse($card->is_active);
        $this->assertNull($card->activated_at);
        $this->assertNull($card->deactivated_at);
        $this->assertSame(40, strlen($result['public_token']));
        $this->assertSame(hash('sha256', $result['public_token']), $row->public_token_hash);
        $this->assertNotSame($result['public_token'], $row->public_token_hash);

        $audit = DB::table('audit_logs')->where('action', AuditLogService::NFC_CARD_CREATED)->first();
        $newValues = json_decode($audit->new_values, true);

        $this->assertSame(NfcCard::class, $audit->entity_type);
        $this->assertArrayNotHasKey('public_token', $newValues);
        $this->assertArrayNotHasKey('public_token_hash', $newValues);
        $this->assertSame('hardware-001', $newValues['card_uid']);
    }

    public function test_create_rejects_duplicate_global_uid_even_after_soft_delete(): void
    {
        $store = $this->createStore();
        $first = $this->service->create(
            $store,
            ['card_uid' => 'hardware-001', 'label' => 'Meja 01', 'type' => 'table'],
            $this->admin,
        );

        $this->service->delete($first['card'], $this->admin);

        try {
            $this->service->create(
                $store,
                ['card_uid' => 'hardware-001', 'label' => 'Meja 02', 'type' => 'table'],
                $this->admin,
            );
            $this->fail('A soft-deleted card UID must remain globally reserved.');
        } catch (ValidationException $exception) {
            $this->assertSame(['UID kartu sudah digunakan.'], $exception->errors()['card_uid']);
        }
    }

    public function test_create_rejects_a_second_non_deleted_card_for_the_same_store(): void
    {
        $store = $this->createStore();
        $this->service->create(
            $store,
            ['card_uid' => 'hardware-001', 'label' => 'Meja 01', 'type' => 'table'],
            $this->admin,
        );

        try {
            $this->service->create(
                $store,
                ['card_uid' => 'hardware-002', 'label' => 'Meja 02', 'type' => 'table'],
                $this->admin,
            );
            $this->fail('A store must not have two non-deleted cards.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Store sudah memiliki kartu NFC.'], $exception->errors()['store_id']);
        }
    }

    public function test_soft_delete_frees_the_store_slot_for_a_replacement_card(): void
    {
        $store = $this->createStore();
        $first = $this->service->create(
            $store,
            ['card_uid' => 'hardware-001', 'label' => 'Meja 01', 'type' => 'table'],
            $this->admin,
        );

        $this->service->delete($first['card'], $this->admin);

        $replacement = $this->service->create(
            $store,
            ['card_uid' => 'hardware-002', 'label' => 'Meja 02', 'type' => 'table'],
            $this->admin,
        );

        $this->assertNotSame($first['card']->id, $replacement['card']->id);
        $this->assertNotNull(DB::table('nfc_cards')->where('id', $first['card']->id)->value('deleted_at'));
        $this->assertSame(2, DB::table('nfc_cards')->count());
        $this->assertSame(1, DB::table('nfc_cards')->whereNull('deleted_at')->count());
    }

    public function test_activation_requires_an_operational_store_and_manages_timestamps(): void
    {
        $store = $this->createStore(active: false);
        $card = $this->createCard($store, 'hardware-001');

        try {
            $this->service->setActive($card, true, $this->admin);
            $this->fail('Activation must require an operational store.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Store tidak operasional.'], $exception->errors()['store_id']);
        }

        $store->is_active = true;
        $store->save();

        $activated = $this->service->setActive($card, true, $this->admin);
        $this->assertTrue($activated->is_active);
        $this->assertNotNull($activated->activated_at);
        $this->assertNull($activated->deactivated_at);

        $deactivated = $this->service->setActive($activated, false, $this->admin);
        $this->assertFalse($deactivated->is_active);
        $this->assertNotNull($deactivated->deactivated_at);

        $reactivated = $this->service->setActive($deactivated, true, $this->admin);
        $this->assertTrue($reactivated->is_active);
        $this->assertNotNull($reactivated->activated_at);
        $this->assertNull($reactivated->deactivated_at);
    }

    public function test_noop_status_changes_do_not_create_audit_entries(): void
    {
        $store = $this->createStore();
        $card = $this->createCard($store, 'hardware-001');

        $this->service->setActive($card, false, $this->admin);
        $this->assertSame(1, DB::table('audit_logs')->count());

        $this->service->setActive($card, true, $this->admin);
        $this->assertSame(2, DB::table('audit_logs')->count());

        $this->service->setActive($card, true, $this->admin);
        $this->assertSame(2, DB::table('audit_logs')->count());

        $this->service->setActive($card, false, $this->admin);
        $this->assertSame(3, DB::table('audit_logs')->count());

        $this->service->setActive($card, false, $this->admin);
        $this->assertSame(3, DB::table('audit_logs')->count());
    }

    public function test_update_only_changes_label_and_type_and_noop_is_not_audited(): void
    {
        $store = $this->createStore();
        $card = $this->createCard($store, 'hardware-001');

        $updated = $this->service->update(
            $card,
            ['label' => 'Meja Baru', 'type' => 'counter', 'card_uid' => 'ignored', 'store_id' => 999],
            $this->admin,
        );

        $this->assertSame('Meja Baru', $updated->label);
        $this->assertSame('counter', $updated->type);
        $this->assertSame('hardware-001', $updated->card_uid);
        $this->assertSame($store->id, $updated->store_id);
        $this->assertSame(2, DB::table('audit_logs')->count());

        $this->service->update($updated, ['label' => 'Meja Baru', 'type' => 'counter'], $this->admin);
        $this->assertSame(2, DB::table('audit_logs')->count());
    }

    public function test_delete_soft_deletes_and_keeps_the_row_and_history(): void
    {
        $store = $this->createStore();
        $card = $this->createCard($store, 'hardware-001');

        $this->service->delete($card, $this->admin);

        $this->assertSame(1, DB::table('nfc_cards')->count());
        $this->assertNotNull(DB::table('nfc_cards')->where('id', $card->id)->value('deleted_at'));
        $this->assertNull(NfcCard::find($card->id));
        $this->assertNotNull(NfcCard::withTrashed()->find($card->id));
        $this->assertSame(1, DB::table('audit_logs')->where('action', AuditLogService::NFC_CARD_DELETED)->count());
    }

    public function test_create_rolls_back_when_audit_fails(): void
    {
        $store = $this->createStore();

        $this->mock(AuditLogService::class, function ($mock): void {
            $mock->shouldReceive('nfcCardSnapshot')->andReturn([]);
            $mock->shouldReceive('recordNfcCard')->once()->andThrow(new RuntimeException('audit failed'));
        });

        $service = app(NfcCardManagementService::class);

        try {
            $service->create(
                $store,
                ['card_uid' => 'hardware-001', 'label' => 'Meja 01', 'type' => 'table'],
                $this->admin,
            );
            $this->fail('The audit failure must abort the transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit failed', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('nfc_cards')->count());
        $this->assertSame(0, DB::table('audit_logs')->count());
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

    private function createCard(Store $store, string $cardUid): NfcCard
    {
        return $this->service->create(
            $store,
            ['card_uid' => $cardUid, 'label' => 'Meja 01', 'type' => 'table'],
            $this->admin,
        )['card'];
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
