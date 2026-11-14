<?php

namespace App\Http\Resources\PublicCustomer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Formats the public store profile for the customer landing page.
 * Internal identifiers, activation flags, and expiry data are never exposed.
 */
class PublicStoreResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => (string) $this->name,
            'description' => $this->description,
            'phone' => $this->phone,
            'address' => $this->address,
            'google_maps_url' => $this->google_maps_url,
            'avg_service_time' => (int) $this->avg_service_time,
            'queue_prefix' => (string) $this->queue_prefix,
            'ordering_mode' => (string) $this->ordering_mode,
        ];
    }
}
