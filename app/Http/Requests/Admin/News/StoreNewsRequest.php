<?php

namespace App\Http\Requests\Admin\News;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreNewsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = [];

        if ($this->has('slug')) {
            $data['slug'] = Str::slug((string) $this->input('slug'));
        }

        if ($this->has('tag_names')) {
            $data['tag_names'] = $this->normalizeTags($this->input('tag_names'));
        }

        if ($this->has('tags') && !$this->has('tag_names')) {
            $data['tag_names'] = $this->normalizeTags($this->input('tags'));
        }

        if ($this->has('is_draft')) {
            $data['is_draft'] = filter_var($this->input('is_draft'), FILTER_VALIDATE_BOOLEAN);
        }

        if ($this->has('is_published')) {
            $data['is_published'] = filter_var($this->input('is_published'), FILTER_VALIDATE_BOOLEAN);
        }

        $this->merge($data);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'content' => ['nullable', 'string'],

            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('news', 'slug'),
            ],

            'title_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'images' => ['nullable', 'array'],
            'images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],

            'is_draft' => ['nullable', 'boolean'],
            'is_published' => ['nullable', 'boolean'],
            'published_at' => ['nullable', 'date'],

            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:255'],

            'tag_names' => ['nullable', 'array'],
            'tag_names.*' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Vui lòng nhập tiêu đề bài viết.',
            'title.max' => 'Tiêu đề không được vượt quá 255 ký tự.',
            'slug.unique' => 'Slug này đã tồn tại.',
            'title_image.image' => 'Ảnh đại diện phải là hình ảnh.',
            'title_image.max' => 'Ảnh đại diện không được vượt quá 4MB.',
            'images.*.image' => 'Ảnh phụ phải là hình ảnh.',
            'images.*.max' => 'Mỗi ảnh phụ không được vượt quá 4MB.',
        ];
    }

    private function normalizeTags(mixed $tags): array
    {
        if (is_string($tags)) {
            $decoded = json_decode($tags, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $tags = $decoded;
            } else {
                $tags = explode(',', $tags);
            }
        }

        if (!is_array($tags)) {
            return [];
        }

        return collect($tags)
            ->map(fn($tag) => trim((string) $tag))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
