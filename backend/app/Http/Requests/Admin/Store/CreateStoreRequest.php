<?php

namespace App\Http\Requests\Admin\Store;

use Illuminate\Support\Str;

class CreateStoreRequest extends AdminStoreRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['name', 'phone', 'queue_prefix', 'description', 'address', 'google_maps_url'] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $this->merge([$key => trim($value)]);
            }
        }

        $slug = $this->input('slug');

        if (is_string($slug) && trim($slug) !== '') {
            $this->merge(['slug' => Str::slug($slug)]);
        }
    }

    protected function collectionAbility(): string
    {
        return 'create';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->withStoreIdProhibited([
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:150', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:stores,slug'],
            'description' => ['nullable', 'string', 'max:5000'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:1000'],
            'google_maps_url' => ['nullable', 'url', 'max:255'],
            'avg_service_time' => ['sometimes', 'required', 'integer', 'min:1', 'max:240'],
            'queue_prefix' => ['sometimes', 'required', 'string', 'max:5'],
            'ordering_mode' => ['sometimes', 'required', 'in:queue_only,queue_and_order'],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'expires_at' => ['required', 'date', 'after:now'],
        ]);
    }
}
