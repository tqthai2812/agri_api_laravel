<?php

namespace App\Http\Requests\Admin\Review;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('review.view') ?? false;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'product_id' => ['nullable', 'integer', 'min:1'],
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'status' => ['nullable', Rule::in(['pending', 'published', 'hidden'])],
            'reply_status' => ['nullable', Rule::in(['answered', 'unanswered'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ];
    }
}
