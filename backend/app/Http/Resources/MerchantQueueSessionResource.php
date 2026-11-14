<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MerchantQueueSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'session_code' => $this->session_code,
            'session_date' => $this->session_date?->toDateString(),
            'last_queue_number' => $this->last_queue_number,
            'status' => $this->status,
            'opened_at' => $this->opened_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
        ];
    }
}
