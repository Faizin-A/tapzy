<?php

namespace App\Http\Requests\Admin\Store;

class UpdateStoreRequest extends AdminStoreRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['name', 'phone', 'queue_prefix', 'description', 'address', 'google_maps_url'] as $key) {
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
        return $this->withStoreIdProhibited([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'google_maps_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'avg_service_time' => ['sometimes', 'integer', 'min:1', 'max:240'],
            'queue_prefix' => ['sometimes', 'string', 'max:5'],
            'ordering_mode' => ['sometimes', 'in:queue_only,queue_and_order'],
            'slug' => ['prohibited'],
            'is_active' => ['prohibited'],
            'expires_at' => ['prohibited'],
        ]);
    }
}
