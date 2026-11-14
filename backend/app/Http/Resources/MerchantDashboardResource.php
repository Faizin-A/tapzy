<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MerchantDashboardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'store' => [
                'name' => $this->resource['store']->name,
                'slug' => $this->resource['store']->slug,
                'description' => $this->resource['store']->description,
                'phone' => $this->resource['store']->phone,
                'address' => $this->resource['store']->address,
                'google_maps_url' => $this->resource['store']->google_maps_url,
                'avg_service_time' => $this->resource['store']->avg_service_time,
                'queue_prefix' => $this->resource['store']->queue_prefix,
                'ordering_mode' => $this->resource['store']->ordering_mode,
                'is_operational' => $this->resource['store']->isOperational(),
            ],
            'queue_session' => $this->resource['queue_session'],
            'queue_counts' => $this->resource['queue_counts'],
            'order_counts' => $this->resource['order_counts'],
        ];
    }
}
