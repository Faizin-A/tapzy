<?php

namespace App\Http\Resources\PublicCustomer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Formats a single product for public customer display.
 * Exposes the product id (needed by the client to build an order payload in
 * later substages) but never exposes store_id or any other internal relation.
 */
class PublicProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'category' => $this->category,
            'image_url' => $this->image_url,
            'is_available' => (bool) $this->is_available,
        ];
    }
}
