<?php

namespace App\Http\Requests\Admin\NfcCard;

class UpdateNfcCardRequest extends AdminNfcCardRequest
{
    protected function prepareForValidation(): void
    {
        $label = $this->input('label');

        if (is_string($label)) {
            $this->merge(['label' => trim($label)]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'required', 'string', 'max:100'],
            'type' => ['sometimes', 'required', 'in:table,queue_kiosk,counter'],
            'card_uid' => ['prohibited'],
            'store_id' => ['prohibited'],
            'is_active' => ['prohibited'],
            'public_token' => ['prohibited'],
            'public_token_hash' => ['prohibited'],
            'activated_at' => ['prohibited'],
            'deactivated_at' => ['prohibited'],
        ];
    }
}
