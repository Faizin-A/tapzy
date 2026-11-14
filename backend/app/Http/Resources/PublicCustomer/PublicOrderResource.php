<?php

namespace App\Http\Resources\PublicCustomer;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Scopes\TenantScope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Formats the customer-facing order confirmation. Internal identifiers,
 * tenant ownership, queue/card references, and customer PII are not exposed.
 *
 * @property Order $resource
 */
class PublicOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;

        $items = $order->relationLoaded('items')
            ? $order->items
            : $order->items()->withoutGlobalScope(TenantScope::class)->orderBy('id')->get();

        return [
            'order_code' => (string) $order->order_code,
            'status' => (string) $order->status,
            'total_items' => (int) $order->total_items,
            'subtotal' => (string) $order->subtotal,
            'total_price' => (string) $order->total_price,
            'notes' => $order->notes,
            'created_at' => $order->created_at?->toIso8601String(),
            'items' => $items
                ->map(fn (OrderItem $item): array => [
                    'product_name' => (string) $item->product_name_snapshot,
                    'unit_price' => (string) $item->unit_price_snapshot,
                    'quantity' => (int) $item->quantity,
                    'subtotal' => (string) $item->subtotal,
                    'notes' => $item->notes,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'success' => true,
            'message' => 'Pesanan berhasil dibuat.',
        ];
    }
}
