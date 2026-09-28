<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $rules = [
            'search' => ['nullable', 'string', 'max:255'],
            'order_status' => [
                'nullable',
                Rule::in(['all', 'pending', 'confirmed', 'shipping', 'completed', 'cancelled']),
            ],
            'payment_method' => ['nullable', Rule::in(['COD', 'VNPAY'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ];

        if ($this->filled('date_from')) {
            $rules['date_to'][] = 'after_or_equal:date_from';
        }

        return $rules;
    }
}
