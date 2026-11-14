<?php

namespace App\Http\Resources\PublicCustomer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps the full catalog response: a sorted list of unique non-null category
 * strings plus the filtered collection of products formatted by
 * PublicProductResource.
 *
 * Returns HTTP 200 with empty arrays when no eligible products exist.
 */
class PublicProductCatalogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'categories' => $this->resource['categories'],
            'products' => PublicProductResource::collection($this->resource['products']),
        ];
    }
}
