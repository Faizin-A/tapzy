<?php

namespace App\Http\Requests\Merchant\Product;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductStatusRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->role === 'merchant'; }

    public function rules(): array
    {
        return [
            'is_active' => ['boolean', 'required_without:is_available'],
            'is_available' => ['boolean', 'required_without:is_active'],
            'store_id' => ['prohibited'],
        ];
    }
}
