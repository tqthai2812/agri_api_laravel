<?php

namespace App\Http\Requests\Client\Wishlist;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWishlistItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('product_id')) {
            $this->merge([
                'product_id' => (int) $this->product_id,
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where(function ($query) {
                    $query->where('is_show', true);
                }),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.required' => 'Vui lòng chọn sản phẩm yêu thích.',
            'product_id.exists' => 'Sản phẩm không tồn tại hoặc đang bị ẩn.',
        ];
    }
}
