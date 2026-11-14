<?php

namespace App\Http\Requests\Merchant\Queue;

use Illuminate\Foundation\Http\FormRequest;

class QueueOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'merchant';
    }

    public function rules(): array
    {
        return [
            'store_id' => ['prohibited'],
        ];
    }
}
