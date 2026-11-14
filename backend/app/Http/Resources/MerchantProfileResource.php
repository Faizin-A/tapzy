<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MerchantProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'phone' => $this->phone,
            'address' => $this->address,
            'google_maps_url' => $this->google_maps_url,
            'avg_service_time' => $this->avg_service_time,
            'queue_prefix' => $this->queue_prefix,
            'ordering_mode' => $this->ordering_mode,
            'is_operational' => $this->isOperational(),
        ];
    }
}
