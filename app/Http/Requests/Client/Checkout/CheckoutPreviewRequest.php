<?php

namespace App\Http\Requests\Client\Checkout;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('discount_code')) {
            $this->merge([
                'discount_code' => strtoupper(trim((string) $this->discount_code)),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'delivery_id' => ['required', 'integer', 'exists:delivery_methods,id'],
            'discount_code' => ['nullable', 'string', 'max:50'],
        ];
    }
}
