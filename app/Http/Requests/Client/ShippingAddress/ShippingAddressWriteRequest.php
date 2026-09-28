<?php

namespace App\Http\Requests\Client\ShippingAddress;

use App\Contracts\Services\LocationServiceInterface;
use App\Models\ShippingAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

abstract class ShippingAddressWriteRequest extends FormRequest
{
    private array $resolvedLocation = [];

    abstract protected function isUpdate(): bool;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $data = [];

        foreach (
            [
                'receiver_name',
                'receiver_phone',
                'province',
                'ward',
                'address_detail',
            ] as $field
        ) {
            if (is_string($this->input($field))) {
                $data[$field] = trim($this->input($field));
            }
        }

        foreach (['province_id', 'ward_id'] as $field) {
            $value = $this->input($field);

            if (is_int($value) || is_string($value)) {
                $data[$field] = trim((string) $value);
            }
        }

        $this->merge($data);
    }

    private function changesLocation(): bool
    {
        return !$this->isUpdate() || $this->hasAny([
            'province',
            'province_id',
            'ward',
            'ward_id',
            'district',
            'district_id',
        ]);
    }

    public function rules(): array
    {
        $presence = $this->isUpdate() ? 'sometimes' : 'required';

        $locationPresence = $this->changesLocation()
            ? 'required'
            : 'sometimes';

        return [
            'receiver_name' => [
                $presence,
                'required',
                'string',
                'max:255',
            ],
            'receiver_phone' => [
                $presence,
                'required',
                'string',
                'max:20',
            ],

            'province_id' => [
                $locationPresence,
                'required',
                'string',
                'regex:/^\d{1,10}$/',
            ],
            'ward_id' => [
                $locationPresence,
                'required',
                'string',
                'regex:/^\d{1,10}$/',
            ],

            // Có thể gửi tên để tương thích frontend.
            // Giá trị lưu sẽ được thay bằng tên chuẩn.
            'province' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ward' => ['sometimes', 'nullable', 'string', 'max:255'],

            // Không nhận huyện cho địa chỉ mới.
            'district' => ['prohibited'],
            'district_id' => ['prohibited'],

            'address_detail' => [
                $presence,
                'required',
                'string',
                'max:255',
            ],

            'address_type' => [
                'sometimes',
                'required',
                Rule::in([
                    ShippingAddress::TYPE_HOME,
                    ShippingAddress::TYPE_OFFICE,
                ]),
            ],

            'is_default' => ['sometimes', 'required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (
                    $validator->errors()->isNotEmpty() ||
                    !$this->changesLocation()
                ) {
                    return;
                }

                try {
                    $this->resolvedLocation = app(
                        LocationServiceInterface::class
                    )->resolve(
                        (string) $this->input('province_id'),
                        (string) $this->input('ward_id')
                    );
                } catch (ValidationException $exception) {
                    foreach ($exception->errors() as $field => $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add($field, $message);
                        }
                    }
                }
            },
        ];
    }

    public function validated($key = null, $default = null)
    {
        $data = array_replace(
            parent::validated(),
            $this->resolvedLocation
        );

        return $key === null
            ? $data
            : Arr::get($data, $key, $default);
    }

    public function messages(): array
    {
        return [
            'receiver_name.required' => 'Vui lòng nhập tên người nhận.',
            'receiver_phone.required' => 'Vui lòng nhập số điện thoại.',
            'province_id.required' => 'Vui lòng chọn tỉnh/thành phố.',
            'ward_id.required' => 'Vui lòng chọn phường/xã.',
            'province_id.regex' => 'Mã tỉnh/thành phố không hợp lệ.',
            'ward_id.regex' => 'Mã phường/xã không hợp lệ.',
            'district.prohibited' => 'Địa chỉ mới không sử dụng quận/huyện.',
            'district_id.prohibited' => 'Địa chỉ mới không sử dụng mã huyện.',
            'address_detail.required' => 'Vui lòng nhập địa chỉ chi tiết.',
            'address_type.in' => 'Loại địa chỉ không hợp lệ.',
            'is_default.boolean' => 'Trạng thái mặc định không hợp lệ.',
        ];
    }
}
