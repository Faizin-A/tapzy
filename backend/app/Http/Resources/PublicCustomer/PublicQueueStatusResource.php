<?php

namespace App\Http\Resources\PublicCustomer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Formats the live queue-status snapshot for public customer display.
 * No internal database IDs, store_id, or any sensitive field is exposed.
 *
 * @property bool $is_session_active
 * @property string|null $session_code
 * @property string $queue_prefix
 * @property string|null $currently_serving
 * @property int|null $currently_serving_number
 * @property int $waiting_count
 * @property int $estimated_wait_minutes
 * @property int $avg_service_time_minutes
 * @property int $last_queue_number
 */
class PublicQueueStatusResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'is_session_active' => $this->resource['is_session_active'],
            'session_code' => $this->resource['session_code'],
            'queue_prefix' => $this->resource['queue_prefix'],
            'currently_serving' => $this->resource['currently_serving'],
            'currently_serving_number' => $this->resource['currently_serving_number'],
            'waiting_count' => $this->resource['waiting_count'],
            'estimated_wait_minutes' => $this->resource['estimated_wait_minutes'],
            'avg_service_time_minutes' => $this->resource['avg_service_time_minutes'],
            'last_queue_number' => $this->resource['last_queue_number'],
        ];
    }
}
