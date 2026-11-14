<?php

namespace App\Http\Requests\Merchant\Product;

class UpdateProductRequest extends StoreProductRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'image_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'is_available' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'store_id' => ['prohibited'],
        ];
    }
}
