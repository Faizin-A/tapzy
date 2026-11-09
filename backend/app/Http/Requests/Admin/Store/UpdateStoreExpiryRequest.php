<?php

namespace App\Http\Requests\Admin\Store;

class UpdateStoreExpiryRequest extends AdminStoreRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->withStoreIdProhibited([
            'expires_at' => ['required', 'date', 'after:now'],
        ]);
    }
}
