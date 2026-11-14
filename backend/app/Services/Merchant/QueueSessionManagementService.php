<?php

namespace App\Services\Merchant;

use App\Models\Queue;
use App\Models\QueueSession;
use App\Models\QueueStatusHistory;
use App\Models\Store;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class QueueSessionManagementService
{
    private const RESET_QUEUE_STATUSES = ['waiting', 'called', 'in_service', 'skipped'];

    private const RESET_ORDER_STATUSES = ['pending', 'confirmed', 'preparing', 'ready'];

    private const RESET_NOTE = 'Auto-cancelled due to session reset';

    public function __construct(private readonly AuditLogService $auditLogService) {}

    public function current(Store $store): ?QueueSession
    {
        return QueueSession::query()->where('store_id', $store->id)->latest('id')->first();
    }

    public function open(Store $store, User $actor, ?string $ip = null, ?string $userAgent = null): QueueSession
    {
        return DB::transaction(function () use ($store, $actor, $ip, $userAgent): QueueSession {
            $lockedStore = Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            if (QueueSession::query()->where('store_id', $lockedStore->id)->where('status', 'active')->exists()) {
                throw new ConflictHttpException('Queue session masih aktif.');
            }

            $session = new QueueSession;
            $session->store_id = $lockedStore->id;
            $session->session_code = $this->uniqueSessionCode($lockedStore->id);
            $session->session_date = now()->toDateString();
            $session->last_queue_number = 0;
            $session->status = 'active';
            $session->opened_at = now();
            $session->save();
            $this->auditLogService->recordQueueSession(AuditLogService::QUEUE_SESSION_OPENED, $session, $actor, [], $this->auditLogService->queueSessionSnapshot($session), $ip, $userAgent);

            return $session->fresh();
        });
    }

    public function close(Store $store, User $actor, ?string $ip = null, ?string $userAgent = null): QueueSession
    {
        return DB::transaction(function () use ($store, $actor, $ip, $userAgent): QueueSession {
            $lockedStore = Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $session = QueueSession::query()->where('store_id', $lockedStore->id)->where('status', 'active')->lockForUpdate()->first();
            if ($session === null) throw new ConflictHttpException('Tidak ada session aktif.');

            $old = $this->auditLogService->queueSessionSnapshot($session);
            $session->status = 'closed';
            $session->closed_at = now();
            $session->save();
            $this->auditLogService->recordQueueSession(AuditLogService::QUEUE_SESSION_CLOSED, $session, $actor, $old, $this->auditLogService->queueSessionSnapshot($session), $ip, $userAgent);

            return $session->fresh();
        });
    }

    public function reset(Store $store, User $actor, ?string $ip = null, ?string $userAgent = null): QueueSession
    {
        return DB::transaction(function () use ($store, $actor, $ip, $userAgent): QueueSession {
            $lockedStore = Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $oldSession = QueueSession::query()->where('store_id', $lockedStore->id)->where('status', 'active')->lockForUpdate()->first();
            if ($oldSession === null) throw new ConflictHttpException('Tidak ada session aktif.');

            $queues = Queue::query()->where('store_id', $lockedStore->id)->where('queue_session_id', $oldSession->id)->whereIn('status', self::RESET_QUEUE_STATUSES)->lockForUpdate()->get(['id', 'status']);
            $now = now();
            $historyRows = $queues->map(fn (Queue $queue): array => [
                'queue_id' => $queue->id,
                'previous_status' => $queue->status,
                'new_status' => 'cancelled',
                'changed_by_user_id' => $actor->id,
                'notes' => self::RESET_NOTE,
                'created_at' => $now,
            ])->all();
            if ($historyRows !== []) DB::table('queue_status_histories')->insert($historyRows);

            $queueIds = $queues->pluck('id')->all();
            if ($queueIds !== []) {
                Queue::query()->whereIn('id', $queueIds)->update(['status' => 'cancelled', 'cancelled_at' => $now, 'updated_at' => $now]);
                DB::table('orders')->where('store_id', $lockedStore->id)->whereIn('queue_id', $queueIds)->whereIn('status', self::RESET_ORDER_STATUSES)->whereNull('deleted_at')->update(['status' => 'cancelled', 'updated_at' => $now]);
            }

            $oldSnapshot = $this->auditLogService->queueSessionSnapshot($oldSession);
            $oldSession->status = 'closed';
            $oldSession->closed_at = $now;
            $oldSession->save();

            $newSession = new QueueSession;
            $newSession->store_id = $lockedStore->id;
            $newSession->session_code = $this->uniqueSessionCode($lockedStore->id);
            $newSession->session_date = $now->toDateString();
            $newSession->last_queue_number = 0;
            $newSession->status = 'active';
            $newSession->opened_at = $now;
            $newSession->save();
            $this->auditLogService->recordQueueSession(AuditLogService::QUEUE_SESSION_RESET, $newSession, $actor, $oldSnapshot, [
                ...$this->auditLogService->queueSessionSnapshot($newSession),
                'cancelled_queue_count' => count($queueIds),
                'cancelled_order_statuses' => self::RESET_ORDER_STATUSES,
            ], $ip, $userAgent);

            return $newSession->fresh();
        });
    }

    private function uniqueSessionCode(int $storeId): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = 'S-'.now()->format('ymd').'-'.Str::upper(Str::random(8));
            if (! QueueSession::withoutGlobalScopes()->where('store_id', $storeId)->where('session_code', $code)->exists()) return $code;
        }

        throw new RuntimeException('Tidak dapat membuat kode session unik.');
    }
}
