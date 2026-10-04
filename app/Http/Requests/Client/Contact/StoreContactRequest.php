<?php

namespace App\Http\Requests\Client\Contact;

use Illuminate\Foundation\Http\FormRequest;

class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }
    protected function prepareForValidation(): void
    {
        foreach (['subject', 'message', 'request_key'] as $key) {
            if (is_string($this->input($key))) $this->merge([$key => trim($this->input($key))]);
        }
    }
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'min:5', 'max:150'],
            'message' => ['required', 'string', 'min:20', 'max:2000'],
            'request_key' => ['required', 'uuid'],
            'user_id' => ['prohibited'],
            'status' => ['prohibited'],
            'admin_note' => ['prohibited'],
            'updated_by' => ['prohibited'],
            'processed_at' => ['prohibited'],
            'lock_version' => ['prohibited']
        ];
    }
    public function messages(): array
    {
        return [
            'subject.required' => 'Vui lòng nhập chủ đề.',
            'subject.min' => 'Chủ đề cần ít nhất 5 ký tự.',
            'subject.max' => 'Chủ đề tối đa 150 ký tự.',
            'message.required' => 'Vui lòng nhập nội dung.',
            'message.min' => 'Nội dung cần ít nhất 20 ký tự.',
            'message.max' => 'Nội dung tối đa 2.000 ký tự.',
            'request_key.required' => 'Thiếu mã gửi yêu cầu. Vui lòng tải lại trang.',
            'request_key.uuid' => 'Mã gửi yêu cầu không hợp lệ.'
        ];
    }
}
