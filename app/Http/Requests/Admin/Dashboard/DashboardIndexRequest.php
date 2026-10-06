<?php

namespace App\Http\Requests\Admin\Dashboard;

use Carbon\CarbonImmutable;
use App\Support\DashboardPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DashboardIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('dashboard.view') ?? false;
    }

    protected function prepareForValidation(): void
    {
        // Missing both dates means current month. Missing only one is a validation error.
        if (!$this->exists('date_from') && !$this->exists('date_to')) {
            $today = CarbonImmutable::now(DashboardPeriod::TIMEZONE);
            $this->merge([
                'date_from' => $today->startOfMonth()->toDateString(),
                'date_to' => $today->toDateString(),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:1970-01-01', 'before:9999-01-01'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from', 'before:9999-01-01'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) return;
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $this->input('date_from'), DashboardPeriod::TIMEZONE);
            $end = CarbonImmutable::createFromFormat('!Y-m-d', $this->input('date_to'), DashboardPeriod::TIMEZONE);
            if ($start->diffInDays($end) > 365) {
                $validator->errors()->add('date_to', 'Mỗi lần xem tối đa 366 ngày.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'date_from.required' => 'Vui lòng chọn ngày bắt đầu.',
            'date_to.required' => 'Vui lòng chọn ngày kết thúc.',
            'date_from.date_format' => 'Ngày bắt đầu không hợp lệ.',
            'date_to.date_format' => 'Ngày kết thúc không hợp lệ.',
            'date_to.after_or_equal' => 'Ngày kết thúc phải từ ngày bắt đầu trở đi.',
        ];
    }
}
