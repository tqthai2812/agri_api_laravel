<?php

namespace App\Http\Requests\Admin\Review;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReviewStatusRequest extends FormRequest
{
    // Route kiểm tra quyền review.moderate hoặc review.reply theo thao tác.
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['published', 'hidden'])],
            'expected_status' => ['required', Rule::in(['published', 'hidden', 'pending'])],
            'content' => ['prohibited'],
            'rating' => ['prohibited'],
            'user_id' => ['prohibited'],
            'product_id' => ['prohibited'],
            'parent_id' => ['prohibited'],
            'order_item_id' => ['prohibited'],
            'is_shop_reply' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Vui lòng chọn trạng thái.',
            'status.in' => 'Chỉ được chọn công khai hoặc ẩn.',
            'expected_status.required' => 'Vui lòng tải lại đánh giá trước khi cập nhật.',
        ];
    }
}
