<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Queue;
use App\Models\QueueStatusHistory;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class QueueStatusHistoryAuditLogModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());

        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('queue_status_histories');
        Schema::dropIfExists('queues');
        Schema::dropIfExists('queue_sessions');
        Schema::dropIfExists('nfc_cards');
        Schema::dropIfExists('users');
        Schema::dropIfExists('stores');

        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 150)->unique();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('session_generation')->default(1);
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

        Schema::create('queue_status_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('queue_id');
            $table->string('previous_status')->nullable();
            $table->string('new_status');
            $table->unsignedBigInteger('changed_by_user_id')->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
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
    }

    public function test_queue_status_history_relationships_and_soft_deleted_actor(): void
    {
        [$store, $queue, $user] = $this->createQueueAndActor();
        $history = $this->createHistory($queue, $user);

        $this->assertSame($queue->id, $history->queue->id);
        $this->assertSame($user->id, $history->changedBy->id);
        $this->assertTrue($queue->statusHistories->contains('id', $history->id));
        $this->assertTrue($user->queueStatusHistories->contains('id', $history->id));
        $this->assertNull($history->previous_status);
        $this->assertSame('waiting', $history->new_status);

        $user->delete();

        $reloadedHistory = QueueStatusHistory::findOrFail($history->id);
        $this->assertSame($user->id, $reloadedHistory->changedBy->id);
        $this->assertNotNull($reloadedHistory->changedBy->deleted_at);
    }

    public function test_audit_log_relationships_json_casts_and_soft_deleted_parents(): void
    {
        $store = $this->createStore();
        $this->useTenant($store);
        $user = $this->createSuperAdmin();
        $auditLog = $this->createAuditLog($store, $user);

        $this->assertSame($store->id, $auditLog->store->id);
        $this->assertSame($user->id, $auditLog->user->id);
        $this->assertTrue($store->auditLogs->contains('id', $auditLog->id));
        $this->assertTrue($user->auditLogs->contains('id', $auditLog->id));
        $this->assertSame(['status' => 'waiting'], $auditLog->old_values);
        $this->assertSame(['status' => 'called'], $auditLog->new_values);
        $this->assertInstanceOf(CarbonInterface::class, $auditLog->created_at);

        $store->delete();
        $user->delete();

        $reloadedAuditLog = AuditLog::findOrFail($auditLog->id);
        $this->assertSame($store->id, $reloadedAuditLog->store->id);
        $this->assertNotNull($reloadedAuditLog->store->deleted_at);
        $this->assertSame($user->id, $reloadedAuditLog->user->id);
        $this->assertNotNull($reloadedAuditLog->user->deleted_at);
    }

    public function test_history_and_audit_log_nullable_parent_fields_are_supported(): void
    {
        [$store, $queue, $user] = $this->createQueueAndActor();
        $history = new QueueStatusHistory;
        $history->queue_id = $queue->id;
        $history->previous_status = null;
        $history->new_status = 'waiting';
        $history->changed_by_user_id = null;
        $history->save();

        $auditLog = new AuditLog;
        $auditLog->store_id = null;
        $auditLog->user_id = null;
        $auditLog->action = 'platform.action';
        $auditLog->save();

        $this->assertNull($history->fresh()->changed_by_user_id);
        $this->assertNull($history->fresh()->previous_status);
        app(TenantContext::class)->clear();
        app(TenantContext::class)->enablePlatformAccess($user);
        $this->assertNull($auditLog->fresh()->store_id);
        $this->assertNull($auditLog->fresh()->user_id);
    }

    public function test_queue_status_history_cannot_be_updated_or_deleted(): void
    {
        [, $queue, $user] = $this->createQueueAndActor();
        $history = $this->createHistory($queue, $user);

        try {
            $history->notes = 'edited history';
            $history->save();
            $this->fail('Queue status history updates must be rejected.');
        } catch (LogicException) {
            $this->assertSame('Original note', $history->fresh()->notes);
        }

        try {
            $history->saveQuietly();
            $this->fail('Quiet queue history updates must be rejected.');
        } catch (LogicException) {
            $this->assertSame('Original note', $history->fresh()->notes);
        }

        try {
            $history->delete();
            $this->fail('Queue status history deletes must be rejected.');
        } catch (LogicException) {
            $this->assertDatabaseHas('queue_status_histories', ['id' => $history->id]);
        }

        try {
            $history->deleteQuietly();
            $this->fail('Quiet queue history deletes must be rejected.');
        } catch (LogicException) {
            $this->assertDatabaseHas('queue_status_histories', ['id' => $history->id]);
        }
    }

    public function test_audit_log_cannot_be_updated_or_deleted(): void
    {
        $store = $this->createStore();
        $user = $this->createSuperAdmin();
        $auditLog = $this->createAuditLog($store, $user);

        try {
            $auditLog->action = 'changed.action';
            $auditLog->save();
            $this->fail('Audit log updates must be rejected.');
        } catch (LogicException) {
            $this->assertSame('queue.status.changed', $auditLog->fresh()->action);
        }

        try {
            $auditLog->saveQuietly();
            $this->fail('Quiet audit log updates must be rejected.');
        } catch (LogicException) {
            $this->assertSame('queue.status.changed', $auditLog->fresh()->action);
        }

        try {
            $auditLog->delete();
            $this->fail('Audit log deletes must be rejected.');
        } catch (LogicException) {
            $this->assertDatabaseHas('audit_logs', ['id' => $auditLog->id]);
        }

        try {
            $auditLog->deleteQuietly();
            $this->fail('Quiet audit log deletes must be rejected.');
        } catch (LogicException) {
            $this->assertDatabaseHas('audit_logs', ['id' => $auditLog->id]);
        }

        try {
            $auditLog->restore();
            $this->fail('Audit logs must not support restore.');
        } catch (LogicException) {
            $this->assertDatabaseHas('audit_logs', ['id' => $auditLog->id]);
        }
    }

    public function test_bulk_eloquent_mutations_are_rejected_and_rows_remain_unchanged(): void
    {
        [, $queue, $user] = $this->createQueueAndActor();
        $history = $this->createHistory($queue, $user);
        $store = $this->createStore();
        $auditLog = $this->createAuditLog($store, $user);

        $mutations = [
            fn () => QueueStatusHistory::query()->whereKey($history->id)->update(['notes' => 'bulk edit']),
            fn () => QueueStatusHistory::query()->whereKey($history->id)->delete(),
            fn () => QueueStatusHistory::query()->whereKey($history->id)->forceDelete(),
            fn () => QueueStatusHistory::query()->whereKey($history->id)->increment('queue_id'),
            fn () => QueueStatusHistory::query()->whereKey($history->id)->decrement('queue_id'),
            fn () => QueueStatusHistory::query()->whereKey($history->id)->incrementEach(['queue_id' => 1]),
            fn () => QueueStatusHistory::query()->whereKey($history->id)->decrementEach(['queue_id' => 1]),
            fn () => QueueStatusHistory::query()->whereKey($history->id)->upsert(
                [['queue_id' => $queue->id, 'new_status' => 'completed']],
                ['id'],
                ['new_status']
            ),
            fn () => QueueStatusHistory::query()->whereKey($history->id)->updateOrInsert(
                ['id' => $history->id],
                ['notes' => 'bulk edit']
            ),
            fn () => QueueStatusHistory::query()->whereKey($history->id)->touch('notes'),
            fn () => QueueStatusHistory::query()->truncate(),
            fn () => AuditLog::query()->whereKey($auditLog->id)->update(['action' => 'bulk.edit']),
            fn () => AuditLog::query()->whereKey($auditLog->id)->delete(),
            fn () => AuditLog::query()->whereKey($auditLog->id)->forceDelete(),
            fn () => AuditLog::query()->whereKey($auditLog->id)->increment('entity_id'),
            fn () => AuditLog::query()->whereKey($auditLog->id)->decrement('entity_id'),
            fn () => AuditLog::query()->whereKey($auditLog->id)->incrementEach(['entity_id' => 1]),
            fn () => AuditLog::query()->whereKey($auditLog->id)->decrementEach(['entity_id' => 1]),
            fn () => AuditLog::query()->whereKey($auditLog->id)->upsert(
                [['action' => 'bulk.edit']],
                ['id'],
                ['action']
            ),
            fn () => AuditLog::query()->whereKey($auditLog->id)->updateOrInsert(
                ['id' => $auditLog->id],
                ['action' => 'bulk.edit']
            ),
            fn () => AuditLog::query()->whereKey($auditLog->id)->touch('action'),
            fn () => AuditLog::query()->truncate(),
        ];

        foreach ($mutations as $mutation) {
            $this->assertMutationRejected($mutation);
        }

        $this->assertSame('Original note', $history->fresh()->notes);
        $this->assertSame('queue.status.changed', $auditLog->fresh()->action);
        $this->assertDatabaseHas('queue_status_histories', ['id' => $history->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $auditLog->id]);
    }

    public function test_mass_assignment_cannot_forge_history_or_audit_fields(): void
    {
        try {
            new QueueStatusHistory([
                'queue_id' => 10,
                'previous_status' => 'waiting',
                'new_status' => 'completed',
                'changed_by_user_id' => 11,
                'notes' => 'forged',
                'created_at' => now(),
            ]);
            $this->fail('Queue status history fields must not be mass assignable.');
        } catch (MassAssignmentException) {
            $this->assertTrue(true);
        }

        try {
            new AuditLog([
                'store_id' => 20,
                'user_id' => 21,
                'action' => 'forged.action',
                'entity_type' => 'Order',
                'entity_id' => 22,
                'old_values' => ['status' => 'pending'],
                'new_values' => ['status' => 'completed'],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'client',
                'created_at' => now(),
            ]);
            $this->fail('Audit log fields must not be mass assignable.');
        } catch (MassAssignmentException) {
            $this->assertTrue(true);
        }
    }

    private function createStore(): Store
    {
        $store = Store::create([
            'name' => 'History Test Store',
            'slug' => 'history-store-'.bin2hex(random_bytes(5)),
        ]);
        $store->is_active = true;

        return $store;
    }

    private function useTenant(Store $store): void
    {
        $user = new User;
        $user->role = 'merchant';
        $user->store_id = $store->getKey();
        app(TenantContext::class)->setMerchantStore($user, $store);
    }

    private function createSuperAdmin(): User
    {
        $user = new User([
            'name' => 'History Actor',
            'email' => 'actor-'.bin2hex(random_bytes(5)).'@example.test',
            'password' => 'secret',
        ]);
        $user->role = 'super_admin';
        $user->store_id = null;
        $user->save();

        return $user;
    }

    private function createQueueAndActor(): array
    {
        $store = $this->createStore();
        $this->useTenant($store);
        $session = $store->queueSessions()->create([
            'session_code' => 'HISTORY-'.bin2hex(random_bytes(4)),
            'session_date' => '2026-11-06',
            'opened_at' => now(),
        ]);
        $queue = $session->queues()->make();
        $queue->store_id = $store->id;
        $queue->queue_number = random_int(1, 900000);
        $queue->formatted_number = 'A-'.bin2hex(random_bytes(3));
        $queue->source = 'manual';
        $queue->access_token_hash = hash('sha256', random_bytes(32));
        $queue->save();

        return [$store, $queue, $this->createSuperAdmin()];
    }

    private function createHistory(Queue $queue, User $user): QueueStatusHistory
    {
        $history = new QueueStatusHistory;
        $history->queue_id = $queue->id;
        $history->previous_status = null;
        $history->new_status = 'waiting';
        $history->changed_by_user_id = $user->id;
        $history->notes = 'Original note';
        $history->save();
        $history->refresh();

        return $history;
    }

    private function createAuditLog(Store $store, User $user): AuditLog
    {
        $auditLog = new AuditLog;
        $auditLog->store_id = $store->id;
        $auditLog->user_id = $user->id;
        $auditLog->action = 'queue.status.changed';
        $auditLog->entity_type = Queue::class;
        $auditLog->entity_id = 1;
        $auditLog->old_values = ['status' => 'waiting'];
        $auditLog->new_values = ['status' => 'called'];
        $auditLog->ip_address = '127.0.0.1';
        $auditLog->user_agent = 'test-agent';
        $auditLog->save();
        $auditLog->refresh();

        return $auditLog;
    }

    private function assertMutationRejected(callable $mutation): void
    {
        try {
            $mutation();
            $this->fail('Append-only Eloquent mutation must be rejected.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
    }
}
