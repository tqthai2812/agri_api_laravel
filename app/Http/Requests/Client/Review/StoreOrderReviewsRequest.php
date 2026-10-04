<?php

namespace App\Http\Requests\Client\Review;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderReviewsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $reviews = $this->input('reviews');

        if (!is_array($reviews)) {
            return;
        }

        foreach ($reviews as &$review) {
            if (is_array($review) && is_string($review['content'] ?? null)) {
                $review['content'] = trim($review['content']);
            }
        }
        unset($review);

        $this->merge(['reviews' => $reviews]);
    }

    public function rules(): array
    {
        return [
            'reviews' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'reviews.*' => ['required', 'array:order_item_id,rating,content'],
            'reviews.*.order_item_id' => ['bail', 'required', 'integer', 'min:1', 'distinct'],
            'reviews.*.rating' => ['bail', 'required', 'integer', 'between:1,5'],
            'reviews.*.content' => ['bail', 'required', 'string', 'max:1000'],
            'user_id' => ['prohibited'],
            'product_id' => ['prohibited'],
            'parent_id' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'reviews.required' => 'Vui lòng nhập đánh giá cho sản phẩm.',
            'reviews.array' => 'Danh sách đánh giá không hợp lệ.',
            'reviews.list' => 'Danh sách đánh giá không hợp lệ.',
            'reviews.min' => 'Vui lòng chọn ít nhất một dòng hàng.',
            'reviews.max' => 'Mỗi lượt gửi tối đa 100 đánh giá.',
            'reviews.*.array' => 'Mỗi đánh giá chỉ gồm dòng hàng, số sao và nội dung.',
            'reviews.*.order_item_id.required' => 'Thiếu dòng hàng cần đánh giá.',
            'reviews.*.order_item_id.integer' => 'Mã dòng hàng không hợp lệ.',
            'reviews.*.order_item_id.min' => 'Mã dòng hàng không hợp lệ.',
            'reviews.*.order_item_id.distinct' => 'Một dòng hàng chỉ được xuất hiện một lần trong lượt gửi.',
            'reviews.*.rating.required' => 'Vui lòng chọn số sao.',
            'reviews.*.rating.integer' => 'Số sao phải là số nguyên từ 1 đến 5.',
            'reviews.*.rating.between' => 'Vui lòng chọn từ 1 đến 5 sao.',
            'reviews.*.content.required' => 'Vui lòng viết nhận xét cho sản phẩm này.',
            'reviews.*.content.string' => 'Nội dung đánh giá phải là văn bản.',
            'reviews.*.content.max' => 'Mỗi nhận xét tối đa 1000 ký tự.',
        ];
    }
}
