<?php

namespace App\Http\Requests\Admin\Store;

class IndexStoreRequest extends AdminStoreRequest
{
    protected function prepareForValidation(): void
    {
        $search = $this->input('search');

        if (is_string($search)) {
            $this->merge(['search' => trim($search)]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->withStoreIdProhibited([
            'search' => ['nullable', 'string', 'max:150'],
            'is_active' => ['nullable', 'boolean'],
            'expiry_status' => ['nullable', 'in:expired,expiring,valid'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
    }
}
