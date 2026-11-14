<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MerchantQueueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $order = $this->resource->relationLoaded('order')
            ? $this->resource->getRelation('order')
            : null;

        return [
            'id' => (int) $this->id,
            'queue_number' => (int) $this->queue_number,
            'formatted_number' => (string) $this->formatted_number,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'queue_notes' => $this->notes,
            'source' => (string) $this->source,
            'status' => (string) $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'called_at' => $this->called_at?->toIso8601String(),
            'served_at' => $this->served_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'has_order' => $this->relationLoaded('order') && $order !== null,
            'order' => $this->relationLoaded('order') && $order !== null ? [
                'id' => (int) $order->id,
                'order_code' => (string) $order->order_code,
                'status' => (string) $order->status,
                'total_items' => (int) $order->total_items,
                'total_price' => (string) $order->total_price,
                'notes' => $order->notes,
                'completed_at' => $order->completed_at?->toIso8601String(),
            ] : null,
        ];
    }
}
