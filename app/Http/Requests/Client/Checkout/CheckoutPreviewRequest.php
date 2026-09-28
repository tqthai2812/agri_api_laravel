<?php

namespace App\Http\Requests\Client\Checkout;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $data = [];

        if (is_string($this->input('discount_code'))) {
            $data['discount_code'] = strtoupper(
                trim($this->input('discount_code'))
            );
        }

        if (is_string($this->input('cart_item_ids'))) {
            // Không intval trước validation.
            // Ví dụ "12abc" phải bị từ chối.
            $data['cart_item_ids'] = array_map(
                'trim',
                explode(',', $this->input('cart_item_ids'))
            );
        }

        foreach (
            [
                'receiver_name',
                'receiver_phone',
                'address_detail',
            ] as $field
        ) {
            if (is_string($this->input($field))) {
                $data[$field] = trim($this->input($field));
            }
        }

        foreach (['province_id', 'ward_id'] as $field) {
            $value = $this->input($field);

            if (is_string($value) || is_int($value)) {
                $data[$field] = trim((string) $value);
            }
        }

        $this->merge($data);
    }

    public function rules(): array
    {
        $userId = $this->user()?->id;

        return [
            'cart_item_ids' => [
                'required',
                'array',
                'list',
                'min:1',
                'max:100',
            ],

            'cart_item_ids.*' => [
                'required',
                'integer',
                'min:1',
                'distinct',
                Rule::exists('cart_items', 'id')->where(
                    fn($query) => $query->whereIn(
                        'cart_id',
                        fn($subquery) => $subquery
                            ->select('id')
                            ->from('shopping_carts')
                            ->where('user_id', $userId)
                    )
                ),
            ],

            'shipping_address_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('shipping_addresses', 'id')
                    ->where('user_id', $userId),
            ],

            'delivery_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('delivery_methods', 'id')
                    ->where('is_active', true),
            ],

            'discount_code' => [
                'nullable',
                'string',
                'max:255',
            ],

            'receiver_name' => [
                'nullable',
                'required_without:shipping_address_id',
                'string',
                'max:255',
            ],

            'receiver_phone' => [
                'nullable',
                'required_without:shipping_address_id',
                'string',
                'max:20',
            ],

            'province_id' => [
                'nullable',
                'required_without:shipping_address_id',
                'string',
                'regex:/^[0-9]{1,10}$/',
            ],

            'ward_id' => [
                'nullable',
                'required_without:shipping_address_id',
                'string',
                'regex:/^[0-9]{1,10}$/',
            ],

            // Tên chính xác được lấy lại từ danh mục trong CheckoutService.
            'province' => [
                'nullable',
                'string',
                'max:255',
            ],

            'ward' => [
                'nullable',
                'string',
                'max:255',
            ],

            // Cho phép thiếu hoặc null, không nhận quận/huyện cho địa chỉ mới.
            'district' => ['prohibited'],
            'district_id' => ['prohibited'],

            'address_detail' => [
                'nullable',
                'required_without:shipping_address_id',
                'string',
                'max:255',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'cart_item_ids.required' =>
            'Vui lòng chọn sản phẩm thanh toán.',

            'cart_item_ids.min' =>
            'Vui lòng chọn ít nhất một dòng hàng.',

            'cart_item_ids.max' =>
            'Mỗi lần thanh toán tối đa 100 dòng hàng.',

            'cart_item_ids.*.exists' =>
            'Dòng hàng không thuộc giỏ của bạn hoặc không còn tồn tại.',

            'cart_item_ids.*.distinct' =>
            'Danh sách dòng hàng bị trùng.',

            'shipping_address_id.exists' =>
            'Địa chỉ không thuộc tài khoản của bạn hoặc đã bị xóa.',

            'delivery_id.required' =>
            'Vui lòng chọn phương thức giao hàng.',

            'delivery_id.exists' =>
            'Phương thức giao hàng không hợp lệ.',

            'receiver_name.required_without' =>
            'Vui lòng nhập tên người nhận.',

            'receiver_phone.required_without' =>
            'Vui lòng nhập số điện thoại người nhận.',

            'province_id.required_without' =>
            'Vui lòng chọn tỉnh/thành phố.',

            'province_id.regex' =>
            'Mã tỉnh/thành phố không hợp lệ.',

            'ward_id.required_without' =>
            'Vui lòng chọn phường/xã.',

            'ward_id.regex' =>
            'Mã phường/xã không hợp lệ.',

            'district.prohibited' =>
            'Địa chỉ mới không sử dụng quận/huyện.',

            'district_id.prohibited' =>
            'Địa chỉ mới không sử dụng mã quận/huyện.',

            'address_detail.required_without' =>
            'Vui lòng nhập địa chỉ cụ thể.',
        ];
    }
}
