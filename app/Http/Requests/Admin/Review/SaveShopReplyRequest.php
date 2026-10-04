<?php

namespace App\Http\Requests\Admin\Review;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveShopReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('review.reply') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('content'))) {
            $this->merge(['content' => trim($this->input('content'))]);
        }
    }

    public function rules(): array
    {
        $updating = $this->isMethod('PATCH');
        return [
            'content' => ['required', 'string', 'max:1000'],
            'expected_content' => $updating ? ['required', 'string', 'max:1000'] : ['prohibited'],
            'expected_status' => $updating
                ? ['required', Rule::in(['published', 'hidden', 'pending'])] : ['prohibited'],
            'rating' => ['prohibited'],
            'user_id' => ['prohibited'],
            'product_id' => ['prohibited'],
            'parent_id' => ['prohibited'],
            'order_item_id' => ['prohibited'],
            'status' => ['prohibited'],
            'is_shop_reply' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'content.required' => 'Vui lòng nhập nội dung phản hồi.',
            'content.max' => 'Phản hồi tối đa 1000 ký tự.',
            'expected_content.required' => 'Tải lại chi tiết trước khi sửa phản hồi.',
            'expected_status.required' => 'Tải lại chi tiết trước khi sửa phản hồi.',
        ];
    }
}
