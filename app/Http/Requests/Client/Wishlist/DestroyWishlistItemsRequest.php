<?php

namespace App\Http\Requests\Client\Wishlist;

use Illuminate\Foundation\Http\FormRequest;

class DestroyWishlistItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->ids)) {
            $ids = collect(explode(',', $this->ids))
                ->map(fn($id) => (int) trim($id))
                ->filter()
                ->values()
                ->all();

            $this->merge([
                'ids' => $ids,
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'Vui lòng chọn sản phẩm cần xóa.',
            'ids.array' => 'Danh sách sản phẩm cần xóa không hợp lệ.',
            'ids.min' => 'Vui lòng chọn ít nhất một sản phẩm.',
            'ids.*.integer' => 'Mã sản phẩm yêu thích không hợp lệ.',
            'ids.*.distinct' => 'Danh sách sản phẩm có giá trị bị trùng.',
        ];
    }
}
