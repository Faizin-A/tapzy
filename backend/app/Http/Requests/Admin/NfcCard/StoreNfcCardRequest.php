<?php

namespace App\Http\Requests\Admin\NfcCard;

use Illuminate\Validation\Rule;

class StoreNfcCardRequest extends AdminNfcCardRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['card_uid', 'label'] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $this->merge([$key => trim($value)]);
            }
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'card_uid' => [
                'required',
                'string',
                'max:100',
                Rule::unique('nfc_cards', 'card_uid'),
            ],
            'label' => ['required', 'string', 'max:100'],
            'type' => ['required', 'in:table,queue_kiosk,counter'],
            'store_id' => ['prohibited'],
            'is_active' => ['prohibited'],
            'public_token' => ['prohibited'],
            'public_token_hash' => ['prohibited'],
            'activated_at' => ['prohibited'],
            'deactivated_at' => ['prohibited'],
        ];
    }
}
