<?php

namespace App\Http\Requests\Merchant\Queue;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'merchant';
    }

    public function rules(): array
    {
        return [
            'tab' => ['nullable', Rule::in(['active', 'completed'])],
            'status' => ['nullable', Rule::in([
                'waiting', 'called', 'in_service', 'skipped', 'completed', 'cancelled',
            ])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
