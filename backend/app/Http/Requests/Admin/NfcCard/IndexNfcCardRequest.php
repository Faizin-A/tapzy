<?php

namespace App\Http\Requests\Admin\NfcCard;

class IndexNfcCardRequest extends AdminNfcCardRequest
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
        return [
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'in:table,queue_kiosk,counter'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
