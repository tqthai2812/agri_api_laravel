<?php

namespace App\Http\Requests\Admin\Contact;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('contact.update') ?? false;
    }
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('admin_note'))) {
            $note = trim($this->input('admin_note'));
            $this->merge(['admin_note' => $note === '' ? null : $note]);
        }
    }
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['pending', 'resolved', 'rejected'])],
            'admin_note' => ['present', 'nullable', 'string', 'max:5000', 'required_if:status,rejected'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'subject' => ['prohibited'],
            'message' => ['prohibited'],
            'user_id' => ['prohibited'],
            'updated_by' => ['prohibited'],
            'processed_at' => ['prohibited'],
            'lock_version' => ['prohibited']
        ];
    }
    public function messages(): array
    {
        return [
            'status.required' => 'Vui lòng chọn trạng thái.',
            'status.in' => 'Trạng thái không hợp lệ.',
            'admin_note.required_if' => 'Vui lòng ghi lý do từ chối trong ghi chú nội bộ.',
            'admin_note.max' => 'Ghi chú tối đa 5.000 ký tự.',
            'expected_version.required' => 'Vui lòng tải lại chi tiết trước khi lưu.'
        ];
    }
}
