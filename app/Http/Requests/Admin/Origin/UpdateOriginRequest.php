<?php

namespace App\Http\Requests\Admin\Origin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOriginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // return $this->user() && $this->user()->role === 'admin';
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $origin = $this->route('origin');
        $originId = $origin ? $origin->id : null;
        return [
            'origin_name' => 'sometimes|string|max:255|unique:origins,origin_name,' . $originId,
            'origin_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ];
    }

    public function messages(): array
    {
        return [
            'origin_name.string' => 'Xuất xứ phải là một chuỗi ký tự.',
            'origin_name.max' => 'Xuất xứ không được vượt quá 255 ký tự.',
            'origin_name.unique' => 'Xuất xứ đã tồn tại.',
            'origin_image.image' => 'Hình ảnh xuất xứ phải là một tệp hình ảnh.',
            'origin_image.mimes' => 'Hình ảnh xuất xứ phải là tệp có định dạng: jpeg, png, jpg, gif.',
            'origin_image.max' => 'Hình ảnh xuất xứ không được vượt quá 2048 kilobytes.',
        ];
    }
}
