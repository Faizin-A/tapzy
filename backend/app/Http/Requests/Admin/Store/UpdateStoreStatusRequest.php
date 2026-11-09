<?php

namespace App\Http\Requests\Admin\Store;

class UpdateStoreStatusRequest extends AdminStoreRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->withStoreIdProhibited([
            'is_active' => ['required', 'boolean'],
        ]);
    }
}
