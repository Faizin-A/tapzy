<?php

namespace App\Http\Requests\Merchant\Queue;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CreateManualQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'merchant';
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (['customer_name', 'queue_notes', 'order_notes'] as $field) {
            if ($this->has($field) && is_string($this->{$field})) {
                $value = trim($this->{$field});
                $data[$field] = $value !== '' ? $value : null;
            }
        }

        if ($this->has('customer_phone') && is_string($this->customer_phone)) {
            $phone = preg_replace('/[\s\-]+/', '', trim($this->customer_phone));
            if ($phone !== '') {
                $phone = str_starts_with($phone, '+62') ? '62'.substr($phone, 3) : (str_starts_with($phone, '0') ? '62'.substr($phone, 1) : $phone);
            }
            $data['customer_phone'] = $phone !== '' ? $phone : null;
        }

        if ($data !== []) {
            $this->merge($data);
        }
    }

    public function rules(): array
    {
        return [
            'store_id' => ['prohibited'],
            'customer_name' => ['required', 'string', 'max:100'],
            'customer_phone' => ['nullable', 'string', 'max:30', 'regex:/^628[1-9][0-9]{7,11}$/'],
            'queue_notes' => ['nullable', 'string', 'max:1000'],
            'order_notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['nullable', 'array', 'max:50'],
            'items.*' => ['required', 'array'],
            'items.*.product_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'between:1,99'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $key = trim((string) $this->header('Idempotency-Key'));
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $key) !== 1) {
                $validator->errors()->add('idempotency_key', 'Header Idempotency-Key wajib berupa UUID v4 yang valid.');
            }
        });
    }

    public function idempotencyKey(): string
    {
        return trim((string) $this->header('Idempotency-Key'));
    }
}
