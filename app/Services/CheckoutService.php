<?php

namespace App\Services;

use App\Contracts\Repositories\CartRepositoryInterface;
use App\Contracts\Services\CheckoutServiceInterface;
use App\Http\Resources\CartResource;
use App\Http\Resources\OrderResource;
use App\Http\Resources\ShippingAddressResource;
use App\Models\DeliveryMethod;
use App\Models\Discount;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderHistory;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ProductPackage;
use App\Models\ShippingAddress;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CheckoutService implements CheckoutServiceInterface
{
    public function __construct(
        protected CartRepositoryInterface $cartRepository
    ) {}

    public function options(User $user): array
    {
        $addresses = ShippingAddress::query()
            ->where('user_id', $user->id)
            ->orderByDesc('is_default')
            ->latest()
            ->get();

        $deliveryMethods = DeliveryMethod::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->latest()
            ->get([
                'id',
                'name',
                'description',
                'base_price',
                'min_order_amount',
                'region',
                'is_default',
            ]);

        return [
            'addresses' => ShippingAddressResource::collection($addresses),

            'delivery_methods' => $deliveryMethods->map(fn($method) => [
                'id' => $method->id,
                'name' => $method->name,
                'description' => $method->description,
                'base_price' => (float) $method->base_price,
                'min_order_amount' => (float) $method->min_order_amount,
                'region' => $method->region,
                'is_default' => (bool) $method->is_default,
            ])->values(),

            'payment_methods' => [
                [
                    'value' => Order::PAYMENT_COD,
                    'label' => 'Thanh toán khi nhận hàng',
                    'description' => 'Thanh toán tiền mặt cho đơn vị vận chuyển.',
                ],
                [
                    'value' => Order::PAYMENT_VNPAY,
                    'label' => 'VNPay',
                    'description' => 'Thanh toán trực tuyến qua cổng VNPay. Phần tích hợp cổng thanh toán có thể triển khai ở giai đoạn sau.',
                ],
            ],
        ];
    }

    public function preview(User $user, array $data): array
    {
        return $this->publicSummary(
            $this->buildSummary($user, $data)
        );
    }

    public function checkout(User $user, array $data): array
    {
        return DB::transaction(function () use ($user, $data) {
            $summary = $this->buildSummary($user, $data, true);
            $addressData = $this->resolveAddressData($user, $data);

            $order = Order::create([
                'user_id' => $user->id,
                'note' => $data['note'] ?? null,
                'delivery_id' => $summary['delivery_method']['id'],
                'discount_amount' => $summary['discount_amount'],
                'discount_id' => $summary['discount']['id'] ?? null,
                'delivery_cost' => $summary['delivery_cost'],
                'total_quantity' => $summary['total_quantity'],
                'total_payment' => $summary['total_payment'],
                'payment_method' => $data['payment_method'],
                'order_status' => Order::STATUS_PENDING,
            ]);

            OrderAddress::create(array_merge([
                'order_id' => $order->id,
            ], $addressData));

            foreach ($summary['selected_items'] as $item) {
                $package = ProductPackage::query()
                    ->where('id', $item->package_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((int) $item->quantity > (int) $package->quantity_available) {
                    throw new RuntimeException('Sản phẩm ' . $package->sku . ' không đủ tồn kho.');
                }

                OrderItem::create([
                    'order_id' => $order->id,
                    'package_id' => $package->id,
                    'quantity' => (int) $item->quantity,
                    'price' => (float) $package->price,
                ]);

                $package->decrement('quantity_available', (int) $item->quantity);

                InventoryTransaction::create([
                    'package_id' => $package->id,
                    'quantity_change' => -abs((int) $item->quantity),
                    'transaction_type' => InventoryTransaction::TYPE_EXPORT,
                    'note' => 'Xuất kho cho đơn hàng DH' . str_pad($order->id, 6, '0', STR_PAD_LEFT),
                    'performed_by' => $user->id,
                ]);
            }

            Payment::create([
                'order_id' => $order->id,
                'payment_method' => $data['payment_method'],
                'transaction_id' => null,
                'amount' => $summary['total_payment'],
                'status' => Payment::STATUS_PENDING,
                'paid_at' => null,
                'failed_reason' => null,
            ]);

            OrderHistory::create([
                'order_id' => $order->id,
                'order_status' => Order::STATUS_PENDING,
                'note' => 'Khách hàng đặt đơn.',
                'created_by' => $user->id,
            ]);

            if (!empty($summary['discount_model'])) {
                $summary['discount_model']->increment('used_count');
            }

            $summary['cart']
                ->items()
                ->whereIn('id', $summary['cart_item_ids'])
                ->delete();

            $order = $order->fresh([
                'user:id,name,email,phone_number',
                'deliveryMethod:id,name,description,base_price,min_order_amount,region',
                'discount:id,discount_code,discount_description,discount_percent,max_discount_amount,min_order_value',
                'payment:id,order_id,payment_method,transaction_id,amount,status,paid_at,failed_reason',
                'orderAddress',
                'histories.creator:id,name,email',
                'items.package:id,variant_id,sku,size,unit,price,quantity_available',
                'items.package.variant:id,product_id,variant_name',
                'items.package.variant.product:id,product_name',
                'items.package.variant.product.images:id,product_id,image_url,is_primary,sort_order',
            ])->loadCount('items');

            return [
                'order' => new OrderResource($order),
                'payment_redirect_url' => null,
            ];
        });
    }

    private function buildSummary(User $user, array $data, bool $lockPackages = false): array
    {
        $cartItemIds = collect($data['cart_item_ids'] ?? [])
            ->map(fn($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($cartItemIds->isEmpty()) {
            throw new RuntimeException('Vui lòng chọn sản phẩm cần thanh toán.');
        }

        $cart = $this->cartRepository->getCartWithItems($user->id);

        if ($cart->items->isEmpty()) {
            throw new RuntimeException('Giỏ hàng đang trống.');
        }

        $selectedItems = $cart->items
            ->whereIn('id', $cartItemIds->all())
            ->values();

        if ($selectedItems->count() !== $cartItemIds->count()) {
            throw new RuntimeException('Một số sản phẩm không tồn tại trong giỏ hàng của bạn.');
        }

        $packageIds = $selectedItems
            ->pluck('package_id')
            ->unique()
            ->values()
            ->all();

        $packagesQuery = ProductPackage::query()
            ->whereIn('id', $packageIds);

        if ($lockPackages) {
            $packagesQuery->lockForUpdate();
        }

        $packages = $packagesQuery->get()->keyBy('id');

        $subtotal = 0;
        $totalQuantity = 0;

        foreach ($selectedItems as $item) {
            $package = $packages->get($item->package_id);

            if (!$package) {
                throw new RuntimeException('Một sản phẩm trong giỏ hàng không còn tồn tại.');
            }

            if ((int) $item->quantity > (int) $package->quantity_available) {
                throw new RuntimeException('Sản phẩm ' . $package->sku . ' không đủ tồn kho.');
            }

            $subtotal += (float) $package->price * (int) $item->quantity;
            $totalQuantity += (int) $item->quantity;
        }

        $deliveryMethod = DeliveryMethod::query()
            ->where('id', $data['delivery_id'])
            ->where('is_active', true)
            ->first();

        if (!$deliveryMethod) {
            throw new RuntimeException('Phương thức giao hàng không hợp lệ hoặc đã bị tắt.');
        }

        $discount = null;
        $discountAmount = 0;

        if (!empty($data['discount_code'])) {
            $discount = $this->validateDiscount(
                $user,
                $data['discount_code'],
                $subtotal,
                $lockPackages
            );

            $discountAmount = min(
                $subtotal * ((int) $discount->discount_percent / 100),
                (float) $discount->max_discount_amount
            );
        }

        $freeShippingThreshold = (float) ($deliveryMethod->min_order_amount ?? 0);

        $deliveryCost = (
            $freeShippingThreshold > 0 &&
            $subtotal >= $freeShippingThreshold
        )
            ? 0
            : (float) $deliveryMethod->base_price;

        $totalPayment = max(0, $subtotal + $deliveryCost - $discountAmount);

        $cart->setRelation('items', $selectedItems);

        return [
            'cart' => $cart,
            'selected_items' => $selectedItems,
            'cart_item_ids' => $cartItemIds->all(),

            'cart_data' => new CartResource($cart),

            'subtotal' => $subtotal,
            'total_quantity' => $totalQuantity,

            'delivery_method' => [
                'id' => $deliveryMethod->id,
                'name' => $deliveryMethod->name,
                'description' => $deliveryMethod->description,
                'base_price' => (float) $deliveryMethod->base_price,
                'min_order_amount' => $freeShippingThreshold,
                'region' => $deliveryMethod->region,
            ],

            'delivery_cost' => $deliveryCost,

            'free_shipping_threshold' => $freeShippingThreshold,

            'missing_for_free_shipping' => $freeShippingThreshold > 0
                ? max(0, $freeShippingThreshold - $subtotal)
                : 0,

            'discount_model' => $discount,

            'discount' => $discount ? [
                'id' => $discount->id,
                'discount_code' => $discount->discount_code,
                'discount_description' => $discount->discount_description,
                'discount_percent' => (int) $discount->discount_percent,
                'max_discount_amount' => (float) $discount->max_discount_amount,
                'min_order_value' => (float) $discount->min_order_value,
            ] : null,

            'discount_amount' => $discountAmount,
            'total_payment' => $totalPayment,
        ];
    }

    private function publicSummary(array $summary): array
    {
        unset(
            $summary['cart'],
            $summary['selected_items'],
            $summary['discount_model']
        );

        return $summary;
    }

    private function validateDiscount(
        User $user,
        string $code,
        float $subtotal,
        bool $lock = false
    ): Discount {
        $query = Discount::query()
            ->where('discount_code', strtoupper(trim($code)));

        if ($lock) {
            $query->lockForUpdate();
        }

        $discount = $query->first();

        if (!$discount) {
            throw new RuntimeException('Mã giảm giá không tồn tại.');
        }

        if (!$discount->is_active) {
            throw new RuntimeException('Mã giảm giá đã bị tắt.');
        }

        if ($discount->expire_date && $discount->expire_date->lt(now()->startOfDay())) {
            throw new RuntimeException('Mã giảm giá đã hết hạn.');
        }

        if ($discount->user_id !== null && (int) $discount->user_id !== (int) $user->id) {
            throw new RuntimeException('Mã giảm giá không áp dụng cho tài khoản này.');
        }

        if (
            $discount->usage_limit !== null &&
            (int) $discount->used_count >= (int) $discount->usage_limit
        ) {
            throw new RuntimeException('Mã giảm giá đã hết lượt sử dụng.');
        }

        if ($subtotal < (float) $discount->min_order_value) {
            throw new RuntimeException('Đơn hàng chưa đạt giá trị tối thiểu để áp dụng mã giảm giá.');
        }

        return $discount;
    }

    private function resolveAddressData(User $user, array $data): array
    {
        if (!empty($data['shipping_address_id'])) {
            $address = ShippingAddress::query()
                ->where('id', $data['shipping_address_id'])
                ->where('user_id', $user->id)
                ->first();

            if (!$address) {
                throw new RuntimeException('Địa chỉ nhận hàng không hợp lệ.');
            }

            return [
                'receiver_name' => $address->receiver_name,
                'receiver_phone' => $address->receiver_phone,
                'province' => $address->province,
                'district' => $address->district,
                'ward' => $address->ward,
                'province_id' => $address->province_id,
                'district_id' => $address->district_id,
                'ward_id' => $address->ward_id,
                'address_detail' => $address->address_detail,
            ];
        }

        return [
            'receiver_name' => $data['receiver_name'],
            'receiver_phone' => $data['receiver_phone'],
            'province' => $data['province'],
            'district' => $data['district'],
            'ward' => $data['ward'],
            'province_id' => $data['province_id'] ?? null,
            'district_id' => $data['district_id'] ?? null,
            'ward_id' => $data['ward_id'] ?? null,
            'address_detail' => $data['address_detail'],
        ];
    }
}
