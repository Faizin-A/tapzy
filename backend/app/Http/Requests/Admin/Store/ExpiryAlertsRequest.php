<?php

namespace App\Http\Requests\Admin\Store;

class ExpiryAlertsRequest extends AdminStoreRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->withStoreIdProhibited([
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'expired_page' => ['nullable', 'integer', 'min:1'],
            'expiring_page' => ['nullable', 'integer', 'min:1'],
        ]);
    }
}
