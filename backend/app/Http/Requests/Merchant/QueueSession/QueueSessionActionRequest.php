<?php

namespace App\Http\Requests\Merchant\QueueSession;

use Illuminate\Foundation\Http\FormRequest;

class QueueSessionActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'merchant';
    }

    public function rules(): array
    {
        return ['store_id' => ['prohibited']];
    }
}
