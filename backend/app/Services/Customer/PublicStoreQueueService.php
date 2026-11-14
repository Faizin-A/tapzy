<?php

namespace App\Services\Customer;

use App\Models\Queue;
use App\Models\QueueSession;
use App\Models\Scopes\TenantScope;
use App\Models\Store;

/**
 * Provides a live snapshot of the store's current queue state for public
 * customer display. Calculations are intentionally lightweight because this
 * service is designed to be called on every polling cycle (5–10 seconds).
 */
class PublicStoreQueueService
{
    /**
     * Compute the live queue status snapshot for the given store.
     *
     * @return array{
     *   is_session_active: bool,
     *   session_code: string|null,
     *   queue_prefix: string,
     *   currently_serving: string|null,
     *   currently_serving_number: int|null,
     *   waiting_count: int,
     *   estimated_wait_minutes: int,
     *   avg_service_time_minutes: int,
     *   last_queue_number: int,
     * }
     */
    public function getStatus(Store $store): array
    {
        $avgServiceTime = (int) $store->avg_service_time;
        $queuePrefix = (string) $store->queue_prefix;

        $session = QueueSession::withoutGlobalScope(TenantScope::class)
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->first();

        if ($session === null) {
            return [
                'is_session_active' => false,
                'session_code' => null,
                'queue_prefix' => $queuePrefix,
                'currently_serving' => null,
                'currently_serving_number' => null,
                'waiting_count' => 0,
                'estimated_wait_minutes' => 0,
                'avg_service_time_minutes' => $avgServiceTime,
                'last_queue_number' => 0,
            ];
        }

        $serving = $this->resolveCurrentlyServing($store->id, $session->id);
        $waitingCount = $this->countWaiting($store->id, $session->id);

        return [
            'is_session_active' => true,
            'session_code' => $session->session_code,
            'queue_prefix' => $queuePrefix,
            'currently_serving' => $serving?->formatted_number,
            'currently_serving_number' => $serving?->queue_number,
            'waiting_count' => $waitingCount,
            'estimated_wait_minutes' => $waitingCount * $avgServiceTime,
            'avg_service_time_minutes' => $avgServiceTime,
            'last_queue_number' => (int) $session->last_queue_number,
        ];
    }

    /**
     * Determine the queue that is currently being served using a strict two-
     * tier priority: in_service first, then called. Within each tier the most
     * recent relevant timestamp wins (served_at or called_at), with the row id
     * as the final tiebreaker to guarantee a fully deterministic result.
     * Skipped, waiting, completed, and cancelled queues are never considered.
     */
    private function resolveCurrentlyServing(int $storeId, int $sessionId): ?Queue
    {
        // Priority 1: in_service – newest served_at, then highest id
        $inService = Queue::withoutGlobalScope(TenantScope::class)
            ->where('store_id', $storeId)
            ->where('queue_session_id', $sessionId)
            ->where('status', 'in_service')
            ->orderByDesc('served_at')
            ->orderByDesc('id')
            ->first();

        if ($inService !== null) {
            return $inService;
        }

        // Priority 2: called – newest called_at, then highest id
        return Queue::withoutGlobalScope(TenantScope::class)
            ->where('store_id', $storeId)
            ->where('queue_session_id', $sessionId)
            ->where('status', 'called')
            ->orderByDesc('called_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Count queues in the given session that are still waiting.
     * Only 'waiting' status is counted; called, in_service, skipped,
     * completed, and cancelled are excluded.
     */
    private function countWaiting(int $storeId, int $sessionId): int
    {
        return (int) Queue::withoutGlobalScope(TenantScope::class)
            ->where('store_id', $storeId)
            ->where('queue_session_id', $sessionId)
            ->where('status', 'waiting')
            ->count();
    }
}
