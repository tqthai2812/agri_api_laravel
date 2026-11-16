<?php

namespace App\Http\Requests\Admin\FarmUtility;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UsageGuideRequest extends FormRequest
{
    public const PAIRS = [
        'dose_per_ha' => 'lượng sản phẩm trên ha',
        'dose_per_liter' => 'lượng sản phẩm trên lít nước',
        'water_l_per_ha' => 'lượng nước trên ha',
    ];

    public function authorize(): bool
    {
        return true; // Quyền được kiểm tra tại controller.
    }

    protected function prepareForValidation(): void
    {
        if (!$this->exists('growth_stage_scope')) $this->merge(['growth_stage_scope' => 'unspecified']);
        if (!$this->exists('growth_stage_ids')) $this->merge(['growth_stage_ids' => []]);
        // PUT thay thế toàn bộ hướng dẫn: trường tùy chọn bỏ trống được lưu NULL.
        $optional = ['dose_unit', 'pre_harvest_days', 'safety_warning', 'label_source'];
        foreach (array_keys(self::PAIRS) as $prefix) {
            $optional[] = $prefix.'_min';
            $optional[] = $prefix.'_max';
        }
        foreach ($optional as $field) {
            if (!$this->exists($field) || $this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
        foreach (['application_method', 'instructions', 'safety_warning', 'label_source'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $this->merge([$field => $value === '' ? null : $value]);
            }
        }
    }

    public function rules(): array
    {
        $rules = [
            'variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')],
            'crop_id' => ['required', 'integer', Rule::exists('crops', 'id')],
            'purpose_id' => ['required', 'integer', Rule::exists('product_purposes', 'id')],
            'purpose' => ['prohibited'],
            'growth_stage' => ['prohibited'],
            'growth_stage_scope' => ['required', Rule::in(['unspecified', 'specific', 'all'])],
            'growth_stage_ids' => ['present', 'array', 'max:100'],
            'growth_stage_ids.*' => ['required', 'integer', 'distinct', Rule::exists('crop_growth_stages', 'id')->where('crop_id', $this->input('crop_id'))],
            'application_method' => ['required', 'string', 'max:100'],
            'dose_unit' => ['nullable', Rule::in(['g', 'ml'])],
            'pre_harvest_days' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'instructions' => ['required', 'string', 'max:16000'],
            'safety_warning' => ['nullable', 'string', 'max:16000'],
            'label_source' => ['nullable', 'string', 'max:1024'],
            'is_active' => ['required', 'boolean'],
        ];

        foreach (array_keys(self::PAIRS) as $prefix) {
            foreach (['min', 'max'] as $bound) {
                // DECIMAL(14,6): nhận số thập phân thông thường, không nhận số mũ,
                // không âm và không cắt ngầm phần thập phân vượt quá 6 chữ số.
                $rules[$prefix.'_'.$bound] = [
                    'nullable', 'regex:/^\d{1,8}(?:\.\d{1,6})?$/D',
                ];
            }
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (self::PAIRS as $prefix => $label) {
                $minField = $prefix.'_min';
                $maxField = $prefix.'_max';
                if ($validator->errors()->has($minField) || $validator->errors()->has($maxField)) {
                    continue;
                }
                $min = $this->input($minField);
                $max = $this->input($maxField);
                if (($min === null) !== ($max === null)) {
                    $validator->errors()->add($min === null ? $minField : $maxField,
                        'Vui lòng nhập đủ hai đầu khoảng '.$label.' hoặc để trống cả hai.');
                } elseif ($min !== null) {
                    $low = $this->scaled($min);
                    $high = $this->scaled($max);
                    if ($low <= 0) {
                        $validator->errors()->add($minField, 'Mức tối thiểu phải lớn hơn 0.');
                    }
                    if ($high < $low) {
                        $validator->errors()->add($maxField, 'Mức tối đa phải lớn hơn hoặc bằng mức tối thiểu.');
                    }
                }
            }

            if (($this->input('dose_per_ha_min') !== null || $this->input('dose_per_liter_min') !== null)
                && $this->input('dose_unit') === null) {
                $validator->errors()->add('dose_unit', 'Vui lòng chọn g hoặc ml khi nhập lượng sản phẩm.');
            }
            $scope = $this->input('growth_stage_scope');
            $ids = $this->input('growth_stage_ids');
            if (is_array($ids)) {
                if ($scope === 'specific' && $ids === []) $validator->errors()->add('growth_stage_ids', 'Chọn ít nhất một giai đoạn của cây trồng.');
                if (in_array($scope, ['all', 'unspecified'], true) && $ids !== []) $validator->errors()->add('growth_stage_ids', 'Phạm vi tất cả/chưa xác định không được có danh sách giai đoạn.');
            }
            if (in_array($this->input('is_active'), [true, 1, '1'], true) && trim((string) $this->input('label_source')) === '') {
                $validator->errors()->add('label_source', 'Cần nguồn nhãn/tài liệu trước khi bật hướng dẫn.');
            }
            // Hướng dẫn chỉ có chữ và định mức cố định (min = max) đều hợp lệ.
        }];
    }

    private function scaled(mixed $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '');

        return (int) ($whole.str_pad($fraction, 6, '0'));
    }

    public function messages(): array
    {
        return [
            '*.regex' => 'Định mức tối đa 8 chữ số nguyên và 6 chữ số thập phân; dùng dấu chấm.',
            'variant_id.exists' => 'Biến thể đã bị xóa hoặc không tồn tại.',
            'growth_stage_ids.*.exists' => 'Giai đoạn phải thuộc cây trồng đang chọn.',
            'growth_stage_ids.*.distinct' => 'Không chọn trùng giai đoạn.',
            'purpose_id.exists' => 'Công dụng không tồn tại.',
            'purpose.prohibited' => 'Dùng purpose_id thay cho mục đích dạng chữ.',
            'growth_stage.prohibited' => 'Dùng growth_stage_scope và growth_stage_ids.',
            'crop_id.exists' => 'Cây trồng đã bị xóa hoặc không tồn tại.',
            'dose_unit.in' => 'Đơn vị lượng sản phẩm chỉ hỗ trợ g hoặc ml.',
        ];
    }

    public function attributes(): array
    {
        return [
            'variant_id' => 'biến thể sản phẩm', 'crop_id' => 'cây trồng',
            'purpose_id' => 'công dụng', 'growth_stage_scope' => 'phạm vi giai đoạn',
            'growth_stage_ids' => 'giai đoạn sinh trưởng',
            'application_method' => 'cách sử dụng', 'dose_unit' => 'đơn vị lượng sản phẩm',
            'instructions' => 'hướng dẫn sử dụng', 'safety_warning' => 'lưu ý an toàn',
            'label_source' => 'nguồn nhãn', 'pre_harvest_days' => 'thời gian cách ly',
            'is_active' => 'trạng thái',
        ];
    }
}