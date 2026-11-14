<?php

namespace App\Services\Customer;

use App\Models\Queue;
use App\Models\QueueSession;
use App\Models\Scopes\TenantScope;
use App\Models\Store;

/**
 * Resolves a customer queue access token into a safe, read-only tracking
 * snapshot. The access token is a bearer capability: the plaintext value is
 * hashed with SHA-256 before lookup and is never persisted or logged here.
 */
class PublicQueueTrackingService
{
    /**
     * Resolve the queue for the given plaintext access token and compute the
     * customer-facing tracking snapshot. Returns null when the token is
     * malformed, unknown, or the owning store has been soft-deleted.
     *
     * @return array{
     *   queue: Queue,
     *   session: QueueSession,
     *   store: Store,
     *   waiting_ahead: int,
     *   estimated_wait_minutes: int,
     *   is_session_active: bool,
     * }|null
     */
    public function track(string $accessToken): ?array
    {
        if (! preg_match('/^[A-Za-z0-9]{40}$/', $accessToken)) {
            return null;
        }

        $queue = Queue::withoutGlobalScope(TenantScope::class)
            ->where('access_token_hash', hash('sha256', $accessToken))
            ->first();

        if ($queue === null) {
            return null;
        }

        // Store uses SoftDeletes, so a soft-deleted store resolves to null and
        // the ticket becomes untrackable. Inactive/expired stores remain
        // trackable as long as the store row still exists.
        $store = Store::query()->whereKey($queue->store_id)->first();

        if ($store === null) {
            return null;
        }

        // QueueSession is tenant-scoped, so load it explicitly with the store
        // filter taken from the resolved queue instead of the request.
        $session = QueueSession::withoutGlobalScope(TenantScope::class)
            ->where('id', $queue->queue_session_id)
            ->where('store_id', $queue->store_id)
            ->first();

        if ($session === null) {
            return null;
        }

        $waitingAhead = Queue::withoutGlobalScope(TenantScope::class)
            ->where('store_id', $queue->store_id)
            ->where('queue_session_id', $queue->queue_session_id)
            ->where('status', 'waiting')
            ->where('queue_number', '<', $queue->queue_number)
            ->count();

        $isSessionActive = $session->status === 'active';

        $estimatedWaitMinutes = $isSessionActive && $queue->status === 'waiting'
            ? $waitingAhead * (int) $store->avg_service_time
            : 0;

        return [
            'queue' => $queue,
            'session' => $session,
            'store' => $store,
            'waiting_ahead' => $waitingAhead,
            'estimated_wait_minutes' => $estimatedWaitMinutes,
            'is_session_active' => $isSessionActive,
        ];
    }
}
