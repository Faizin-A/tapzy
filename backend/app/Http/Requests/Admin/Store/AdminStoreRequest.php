<?php

namespace App\Http\Requests\Admin\Store;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

abstract class AdminStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return false;
        }

        $store = $this->route('store');

        if ($store instanceof Store) {
            return Gate::forUser($user)->allows('manage', $store);
        }

        return Gate::forUser($user)->allows($this->collectionAbility(), Store::class);
    }

    protected function collectionAbility(): string
    {
        return 'viewAny';
    }

    /**
     * @param  array<string, array<int, mixed>>  $rules
     * @return array<string, array<int, mixed>>
     */
    protected function withStoreIdProhibited(array $rules): array
    {
        $rules['store_id'] = ['prohibited'];

        return $rules;
    }
}
