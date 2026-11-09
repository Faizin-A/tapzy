<?php

namespace App\Http\Requests\Admin\NfcCard;

class UpdateNfcCardStatusRequest extends AdminNfcCardRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
            'store_id' => ['prohibited'],
            'card_uid' => ['prohibited'],
            'public_token' => ['prohibited'],
            'public_token_hash' => ['prohibited'],
        ];
    }
}
