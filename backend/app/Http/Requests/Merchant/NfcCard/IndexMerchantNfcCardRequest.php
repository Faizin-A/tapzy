<?php

namespace App\Http\Requests\Merchant\NfcCard;

use App\Http\Requests\TenantOwnedResourceRequest;
use App\Models\NfcCard;

class IndexMerchantNfcCardRequest extends TenantOwnedResourceRequest
{
    protected function prepareForValidation(): void
    {
        $search = $this->input('search');

        if (is_string($search)) {
            $this->merge(['search' => trim($search)]);
        }
    }

    /**
     * @return class-string<NfcCard>
     */
    protected function resourceModelClass(): string
    {
        return NfcCard::class;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function resourceRules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'in:table,queue_kiosk,counter'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
