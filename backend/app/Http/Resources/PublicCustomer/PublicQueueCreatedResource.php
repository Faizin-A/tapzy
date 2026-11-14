<?php

namespace App\Http\Resources\PublicCustomer;

use App\Models\Queue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicQueueCreatedResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Queue $queue */
        $queue = $this->resource['queue'];

        return [
            'queue_number' => (int) $queue->queue_number,
            'formatted_number' => (string) $queue->formatted_number,
            'session_code' => $this->resource['session_code'],
            'status' => (string) $queue->status,
            'customer_name' => $queue->customer_name,
            'customer_phone' => $queue->customer_phone,
            'waiting_ahead' => (int) $this->resource['waiting_ahead'],
            'estimated_wait_minutes' => (int) $this->resource['estimated_wait_minutes'],
            'access_token' => $this->resource['access_token'],
            'created_at' => $queue->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        $isReplay = (bool) ($this->resource['is_replay'] ?? false);

        return [
            'success' => true,
            'message' => $isReplay
                ? 'Permintaan antrean telah diproses sebelumnya.'
                : 'Antrean berhasil diambil.',
        ];
    }
}
