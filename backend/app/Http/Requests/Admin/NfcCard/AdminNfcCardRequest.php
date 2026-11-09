<?php

namespace App\Http\Requests\Admin\NfcCard;

use App\Models\NfcCard;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

abstract class AdminNfcCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return false;
        }

        $nfcCard = $this->route('nfcCard');

        if ($nfcCard instanceof NfcCard) {
            $ability = match ($this->method()) {
                'GET', 'HEAD' => 'view',
                'DELETE' => 'delete',
                default => 'update',
            };

            return Gate::forUser($user)->allows($ability, $nfcCard);
        }

        $store = $this->route('store');

        if ($store instanceof Store) {
            return Gate::forUser($user)->allows('manage', $store)
                && Gate::forUser($user)->allows('create', NfcCard::class);
        }

        return Gate::forUser($user)->allows($this->collectionAbility(), NfcCard::class);
    }

    protected function collectionAbility(): string
    {
        return 'viewAny';
    }
}
