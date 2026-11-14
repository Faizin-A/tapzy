<?php

namespace App\Http\Requests\PublicCustomer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CreateCustomerQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('customer_phone') && is_string($this->customer_phone)) {
            $cleaned = preg_replace('/[\s\-]+/', '', trim($this->customer_phone));

            if ($cleaned !== '') {
                if (str_starts_with($cleaned, '+62')) {
                    $cleaned = '62'.substr($cleaned, 3);
                } elseif (str_starts_with($cleaned, '0')) {
                    $cleaned = '62'.substr($cleaned, 1);
                }
                $this->merge(['customer_phone' => $cleaned]);
            } else {
                $this->merge(['customer_phone' => null]);
            }
        }

        if ($this->has('customer_name') && is_string($this->customer_name)) {
            $trimmed = trim($this->customer_name);
            $this->merge(['customer_name' => $trimmed !== '' ? $trimmed : null]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'customer_name' => ['nullable', 'string', 'max:100'],
            'customer_phone' => ['nullable', 'string', 'max:30', 'regex:/^628[1-9][0-9]{7,11}$/'],
            'source' => ['nullable', 'string', 'in:nfc,barcode'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_phone.regex' => 'Nomor telepon harus berupa nomor seluler Indonesia yang valid (contoh: 08123456789 atau +628123456789).',
            'source.in' => 'Sumber antrean harus berupa nfc atau barcode.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $key = $this->header('Idempotency-Key');

            if (! is_string($key) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', trim($key)) !== 1) {
                $validator->errors()->add(
                    'idempotency_key',
                    'Header Idempotency-Key wajib berupa UUID v4 yang valid.'
                );
            }
        });
    }

    public function getIdempotencyKey(): string
    {
        return trim((string) $this->header('Idempotency-Key'));
    }
}
