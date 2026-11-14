<?php

namespace App\Http\Requests\Merchant\Product;

use Illuminate\Foundation\Http\FormRequest;

class IndexProductRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->role === 'merchant'; }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'is_available' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'sort' => ['sometimes', 'in:name,price,created_at,updated_at'],
            'direction' => ['sometimes', 'in:asc,desc'],
            'store_id' => ['prohibited'],
        ];
    }
}
