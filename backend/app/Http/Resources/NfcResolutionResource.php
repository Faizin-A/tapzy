<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NfcResolutionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'store' => [
                'name' => $this->store->name,
                'slug' => $this->store->slug,
                'description' => $this->store->description,
                'phone' => $this->store->phone,
                'address' => $this->store->address,
                'google_maps_url' => $this->store->google_maps_url,
                'avg_service_time' => $this->store->avg_service_time,
                'queue_prefix' => $this->store->queue_prefix,
                'ordering_mode' => $this->store->ordering_mode,
            ],
            'card' => [
                'label' => $this->label,
                'type' => $this->type,
            ],
        ];
    }
}
