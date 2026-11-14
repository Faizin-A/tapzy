<?php

namespace App\Http\Resources\PublicCustomer;

use App\Models\Queue;
use App\Models\QueueSession;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Formats a customer queue-tracking snapshot. Only fields that are safe for
 * the holder of the access token are exposed; internal IDs, token hashes, and
 * customer PII are never included.
 *
 * @property array{
 *   queue: Queue,
 *   session: QueueSession,
 *   store: Store,
 *   waiting_ahead: int,
 *   estimated_wait_minutes: int,
 *   is_session_active: bool,
 * } $resource
 */
class PublicQueueTrackingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Queue $queue */
        $queue = $this->resource['queue'];

        /** @var QueueSession $session */
        $session = $this->resource['session'];

        /** @var Store $store */
        $store = $this->resource['store'];

        return [
            'queue_number' => (int) $queue->queue_number,
            'formatted_number' => (string) $queue->formatted_number,
            'status' => (string) $queue->status,
            'waiting_ahead' => (int) $this->resource['waiting_ahead'],
            'estimated_wait_minutes' => (int) $this->resource['estimated_wait_minutes'],
            'created_at' => $queue->created_at?->toIso8601String(),
            'called_at' => $queue->called_at?->toIso8601String(),
            'served_at' => $queue->served_at?->toIso8601String(),
            'completed_at' => $queue->completed_at?->toIso8601String(),
            'cancelled_at' => $queue->cancelled_at?->toIso8601String(),
            'session' => [
                'session_code' => (string) $session->session_code,
                'session_date' => $session->session_date?->toDateString(),
                'is_active' => (bool) $this->resource['is_session_active'],
            ],
            'store' => [
                'name' => (string) $store->name,
                'queue_prefix' => (string) $store->queue_prefix,
            ],
        ];
    }
}
