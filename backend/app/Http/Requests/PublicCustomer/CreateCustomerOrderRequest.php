<?php

namespace App\Http\Requests\PublicCustomer;

use Illuminate\Foundation\Http\FormRequest;

class CreateCustomerOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('notes') && is_string($this->notes)) {
            $notes = trim($this->notes);
            $this->merge(['notes' => $notes !== '' ? $notes : null]);
        }

        if (! is_array($this->items)) {
            return;
        }

        $items = [];

        foreach ($this->items as $item) {
            if (! is_array($item)) {
                $items[] = $item;

                continue;
            }

            if (array_key_exists('notes', $item) && is_string($item['notes'])) {
                $notes = trim($item['notes']);
                $item['notes'] = $notes !== '' ? $notes : null;
            }

            $items[] = $item;
        }

        $this->merge(['items' => $items]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*' => ['required', 'array'],
            'items.*.product_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'between:1,99'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Pesanan harus memiliki minimal satu produk.',
            'items.array' => 'Daftar produk tidak valid.',
            'items.min' => 'Pesanan harus memiliki minimal satu produk.',
            'items.max' => 'Maksimal 50 jenis produk dalam satu pesanan.',
            'items.*.product_id.required' => 'Produk wajib dipilih.',
            'items.*.product_id.integer' => 'Produk tidak valid.',
            'items.*.product_id.distinct' => 'Produk yang sama tidak boleh dikirim lebih dari sekali.',
            'items.*.quantity.required' => 'Jumlah produk wajib diisi.',
            'items.*.quantity.integer' => 'Jumlah produk harus berupa angka bulat.',
            'items.*.quantity.between' => 'Jumlah produk harus antara 1 sampai 99.',
            'items.*.notes.max' => 'Catatan produk maksimal 255 karakter.',
            'notes.max' => 'Catatan pesanan maksimal 1000 karakter.',
        ];
    }
}
