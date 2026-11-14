<?php

namespace App\Http\Requests\Merchant\Product;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->role === 'merchant'; }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'description', 'category', 'image_url'] as $key) {
            if (is_string($this->input($key))) $this->merge([$key => trim($this->input($key))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'image_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'is_available' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'store_id' => ['prohibited'],
        ];
    }
}
