<?php

namespace App\Http\Requests\Merchant;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['name', 'phone', 'queue_prefix', 'description', 'address', 'google_maps_url'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => trim($this->input($key))]);
            }
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->role === 'merchant';
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'google_maps_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'avg_service_time' => ['sometimes', 'integer', 'min:1', 'max:240'],
            'queue_prefix' => ['sometimes', 'string', 'max:5'],
            'ordering_mode' => ['sometimes', 'in:queue_only,queue_and_order'],
            'store_id' => ['prohibited'],
            'slug' => ['prohibited'],
            'is_active' => ['prohibited'],
            'expires_at' => ['prohibited'],
            'role' => ['prohibited'],
        ];
    }
}
