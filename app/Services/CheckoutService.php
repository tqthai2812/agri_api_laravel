<?php

namespace App\Services;

use App\Contracts\Repositories\CartRepositoryInterface;
use App\Contracts\Services\CheckoutServiceInterface;
use App\Contracts\Services\LocationServiceInterface;
use App\Contracts\Services\OrderStockServiceInterface;
use App\Http\Resources\CartResource;
use App\Http\Resources\OrderResource;
use App\Http\Resources\ShippingAddressResource;
use App\Models\DeliveryMethod;
use App\Models\Discount;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderHistory;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ShippingAddress;
use App\Models\User;
use App\Support\OrderMoney;
use App\Support\OrderRelations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckoutService implements CheckoutServiceInterface
{
    public function __construct(
        protected CartRepositoryInterface $cartRepository,
        protected OrderStockServiceInterface $stockService,
        protected LocationServiceInterface $locationService
    ) {}

    public function options(User $user): array
    {
        $addresses = ShippingAddress::query()
            ->where('user_id', $user->id)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();

        $methods = DeliveryMethod::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();

        return [
            'addresses' => ShippingAddressResource::collection($addresses),

            'delivery_methods' => $methods->map(fn($method) => [
                'id' => $method->id,
                'name' => $method->name,
                'description' => $method->description,
                'base_price' => (float) $method->base_price,
                'min_order_amount' => (float) $method->min_order_amount,
                'region' => $method->region,
                'is_default' => (bool) $method->is_default,
                'requires_address_validation' => true,
            ])->values(),

            'payment_methods' => [
                [
                    'value' => 'COD',
                    'label' => 'Thanh toán khi nhận hàng',
                    'description' => 'Thanh toán khi nhận hàng.',
                    'enabled' => true,
                ],
                [
                    'value' => 'VNPAY',
                    'label' => 'VNPay',
                    'description' => 'Sẽ được tích hợp sau.',
                    'enabled' => false,
                ],
            ],
        ];
    }

    public function preview(User $user, array $data): array
    {
        $summary = $this->buildSummary($user, $data, false);

        return $summary['public'];
    }

    public function checkout(User $user, array $data): array
    {
        if (($data['payment_method'] ?? null) !== 'COD') {
            $this->fail(
                'payment_method',
                'Thanh toán trực tuyến chưa được kích hoạt.'
            );
        }

        return DB::transaction(function () use ($user, $data) {
            $summary = $this->buildSummary($user, $data, true);
            $public = $summary['public'];

            $order = Order::create([
                'user_id' => $user->id,
                'note' => $data['note'] ?? null,
                'delivery_id' => $summary['delivery']->id,
                'discount_id' => $summary['discount']?->id,
                'discount_amount' => OrderMoney::decimal(
                    $summary['discount_cents']
                ),
                'delivery_cost' => OrderMoney::decimal(
                    $summary['delivery_cents']
                ),
                'total_quantity' => $public['total_quantity'],
                'total_payment' => OrderMoney::decimal(
                    $summary['total_cents']
                ),
                'payment_method' => 'COD',
                'order_status' => 'pending',
                'completed_at' => null,
            ]);

            OrderAddress::create([
                'order_id' => $order->id,
                ...$summary['address'],
            ]);

            $allocated = 0;
            $cumulativeGross = 0;

            foreach ($summary['items'] as $item) {
                $package = $item->package;

                $gross = OrderMoney::multiply(
                    OrderMoney::cents($package->price),
                    (int) $item->quantity
                );

                $cumulativeGross += $gross;

                // Phân bổ theo tổng lũy kế để tổng các dòng khớp tuyệt đối.
                $target = OrderMoney::proportional(
                    $summary['discount_cents'],
                    $cumulativeGross,
                    $summary['subtotal_cents']
                );

                $lineDiscount = $target - $allocated;
                $allocated = $target;

                OrderItem::create([
                    'order_id' => $order->id,
                    'package_id' => $package->id,
                    'quantity' => (int) $item->quantity,
                    'price' => $package->price,

                    'product_name' => $package->variant->product->product_name,
                    'variant_name' => $package->variant->variant_name,
                    'sku' => $package->sku,
                    'size' => $package->size,
                    'unit' => $package->unit,

                    'discount_amount' => OrderMoney::decimal($lineDiscount),
                    'net_sales_amount' => OrderMoney::decimal(
                        $gross - $lineDiscount
                    ),
                    'cost_total' => null,
                ]);
            }

            $this->stockService->reserve($order);

            Payment::create([
                'order_id' => $order->id,
                'payment_method' => 'COD',
                'transaction_id' => null,
                'amount' => $order->total_payment,
                'status' => 'pending',
                'paid_at' => null,
                'failed_reason' => null,
            ]);

            OrderHistory::create([
                'order_id' => $order->id,
                'order_status' => 'pending',
                'note' => 'Khách hàng đặt đơn.',
                'created_by' => $user->id,
            ]);

            if ($summary['discount']) {
                $summary['discount']->increment('used_count');
            }

            $deleted = $this->cartRepository->deleteItemsByIds(
                $summary['cart'],
                $summary['ids']
            );

            if ($deleted !== count($summary['ids'])) {
                $this->fail(
                    'cart_item_ids',
                    'Giỏ hàng đã thay đổi. Vui lòng tải lại.'
                );
            }

            $order->load(OrderRelations::detail())->loadCount('items');

            return [
                'order' => new OrderResource($order),

                // Chưa kích hoạt thanh toán trực tuyến.
                'payment_redirect_url' => null,
            ];
        }, 3);
    }

    private function buildSummary(
        User $user,
        array $data,
        bool $lock
    ): array {
        $ids = array_values(array_unique(array_map(
            'intval',
            $data['cart_item_ids'] ?? []
        )));

        if (!$ids) {
            $this->fail(
                'cart_item_ids',
                'Vui lòng chọn dòng hàng.'
            );
        }

        $cart = $lock
            ? $this->cartRepository->lockCart($user->id)
            : $this->cartRepository->getOrCreateCart($user->id);

        $items = $this->cartRepository->selectedItemsForUser(
            $user->id,
            $ids,
            $lock
        );

        if ($items->count() !== count($ids)) {
            $this->fail(
                'cart_item_ids',
                'Một số dòng hàng không còn trong giỏ của bạn.'
            );
        }

        $packages = $this->stockService->availability(
            $items->pluck('package_id')->all(),
            $lock
        );

        $subtotal = 0;
        $totalQuantity = 0;

        foreach ($items->groupBy('package_id') as $packageId => $group) {
            $package = $packages->get($packageId);
            $quantity = (int) $group->sum('quantity');

            if (
                !$package
                || !$package->variant?->product
                || !$package->variant->product->is_show
            ) {
                $this->fail(
                    'cart_item_ids',
                    'Có sản phẩm đã ngừng kinh doanh.'
                );
            }

            if ($quantity > (int) $package->available_to_sell) {
                $this->fail(
                    'cart_item_ids',
                    "SKU {$package->sku} không đủ hàng có thể bán."
                );
            }
        }

        foreach ($items as $item) {
            $quantity = (int) $item->quantity;

            if ($quantity < 1) {
                $this->fail(
                    'cart_item_ids',
                    'Số lượng dòng hàng không hợp lệ.'
                );
            }

            $package = $packages->get($item->package_id);
            $item->setRelation('package', $package);

            $subtotal = OrderMoney::add(
                $subtotal,
                OrderMoney::multiply(
                    OrderMoney::cents($package->price),
                    $quantity
                )
            );

            $totalQuantity += $quantity;

            if ($totalQuantity > 2147483647) {
                $this->fail(
                    'cart_item_ids',
                    'Tổng số lượng vượt giới hạn.'
                );
            }
        }

        OrderMoney::assertOrderAmount($subtotal);

        $address = $this->resolveAddress($user, $data, $lock);

        $deliveryQuery = DeliveryMethod::query()
            ->whereKey($data['delivery_id'])
            ->where('is_active', true);

        if ($lock) {
            $deliveryQuery->sharedLock();
        }

        $delivery = $deliveryQuery->first();

        if (!$delivery) {
            $this->fail(
                'delivery_id',
                'Phương thức giao hàng không hợp lệ.'
            );
        }

        $this->assertRegion($delivery, $address);

        $discount = null;
        $discountCents = 0;

        if (!empty($data['discount_code'])) {
            $query = Discount::query()
                ->where(
                    'discount_code',
                    strtoupper(trim($data['discount_code']))
                );

            if ($lock) {
                $query->lockForUpdate();
            }

            $discount = $query->first();

            if (
                !$discount
                || !$discount->is_active
                || !$discount->expire_date
                || $discount->expire_date->toDateString() < now()->toDateString()
                || (
                    $discount->user_id !== null
                    && (int) $discount->user_id !== (int) $user->id
                )
                || (
                    $discount->usage_limit !== null
                    && (int) $discount->used_count >= (int) $discount->usage_limit
                )
            ) {
                $this->fail(
                    'discount_code',
                    'Mã giảm giá không hợp lệ, đã hết hạn hoặc hết lượt.'
                );
            }

            $percent = (int) $discount->discount_percent;

            if ($percent < 1 || $percent > 100) {
                $this->fail(
                    'discount_code',
                    'Phần trăm giảm giá không hợp lệ.'
                );
            }

            if ($subtotal < OrderMoney::cents($discount->min_order_value)) {
                $this->fail(
                    'discount_code',
                    'Chưa đạt mức tiền hàng tối thiểu của mã giảm giá.'
                );
            }

            // Làm tròn half-up đến 2 chữ số thập phân.
            $discountCents = min(
                intdiv($subtotal * $percent + 50, 100),
                OrderMoney::cents($discount->max_discount_amount),
                $subtotal
            );
        }

        $net = $subtotal - $discountCents;
        $minimum = OrderMoney::cents($delivery->min_order_amount);

        if ($net < $minimum) {
            $this->fail(
                'delivery_id',
                'Tiền hàng sau giảm giá chưa đạt mức tối thiểu của phương thức giao hàng.'
            );
        }

        // Phí cố định theo phương thức.
        // min_order_amount là điều kiện áp dụng, không phải ngưỡng miễn phí.
        $deliveryCents = OrderMoney::cents($delivery->base_price);
        $totalCents = OrderMoney::add($net, $deliveryCents);

        OrderMoney::assertOrderAmount($totalCents);

        $cart->setRelation('items', $items);

        return [
            'cart' => $cart,
            'items' => $items,
            'ids' => $ids,
            'address' => $address,
            'delivery' => $delivery,
            'discount' => $discount,
            'subtotal_cents' => $subtotal,
            'discount_cents' => $discountCents,
            'delivery_cents' => $deliveryCents,
            'total_cents' => $totalCents,

            'public' => [
                'cart_item_ids' => $ids,
                'cart_data' => new CartResource($cart),
                'subtotal' => (float) OrderMoney::decimal($subtotal),
                'total_quantity' => $totalQuantity,

                'delivery_method' => [
                    'id' => $delivery->id,
                    'name' => $delivery->name,
                    'description' => $delivery->description,
                    'base_price' => (float) $delivery->base_price,
                    'min_order_amount' => (float) $delivery->min_order_amount,
                    'region' => $delivery->region,
                ],

                'delivery_cost' => (float) OrderMoney::decimal(
                    $deliveryCents
                ),

                'discount' => $discount ? [
                    'id' => $discount->id,
                    'discount_code' => $discount->discount_code,
                    'discount_description' => $discount->discount_description,
                    'discount_percent' => (int) $discount->discount_percent,
                    'max_discount_amount' => (float) $discount->max_discount_amount,
                    'min_order_value' => (float) $discount->min_order_value,
                ] : null,

                'discount_amount' => (float) OrderMoney::decimal(
                    $discountCents
                ),

                'total_payment' => (float) OrderMoney::decimal(
                    $totalCents
                ),
            ],
        ];
    }

    private function resolveAddress(
        User $user,
        array $data,
        bool $lock
    ): array {
        $usesSavedAddress = !empty($data['shipping_address_id']);

        if ($usesSavedAddress) {
            $query = ShippingAddress::query()
                ->whereKey($data['shipping_address_id'])
                ->where('user_id', $user->id);

            if ($lock) {
                $query->sharedLock();
            }

            $savedAddress = $query->first();

            if (!$savedAddress) {
                $this->fail(
                    'shipping_address_id',
                    'Địa chỉ nhận hàng không thuộc tài khoản của bạn hoặc đã bị xóa.'
                );
            }

            // Yêu cầu người dùng cập nhật địa chỉ cũ.
            // Không tự suy đoán địa danh mới từ tên quận/huyện cũ.
            if (
                trim((string) $savedAddress->district) !== ''
                || trim((string) $savedAddress->district_id) !== ''
                || trim((string) $savedAddress->province_id) === ''
                || trim((string) $savedAddress->ward_id) === ''
            ) {
                $this->fail(
                    'shipping_address_id',
                    'Vui lòng cập nhật địa chỉ nhận hàng theo tỉnh/thành và phường/xã mới trước khi thanh toán.'
                );
            }

            $source = $savedAddress->only([
                'receiver_name',
                'receiver_phone',
                'province_id',
                'ward_id',
                'address_detail',
            ]);
        } else {
            $source = $data;

            foreach (['district', 'district_id'] as $field) {
                if (
                    isset($source[$field])
                    && $source[$field] !== ''
                ) {
                    $this->fail(
                        $field,
                        'Địa chỉ mới chỉ sử dụng tỉnh/thành và phường/xã.'
                    );
                }
            }
        }

        foreach (
            [
                'receiver_name',
                'receiver_phone',
                'province_id',
                'ward_id',
                'address_detail',
            ] as $field
        ) {
            $value = $source[$field] ?? null;

            if (
                (!is_string($value) && !is_int($value))
                || trim((string) $value) === ''
            ) {
                $this->fail(
                    $usesSavedAddress ? 'shipping_address_id' : $field,
                    'Vui lòng bổ sung đầy đủ thông tin địa chỉ nhận hàng.'
                );
            }
        }

        // Xác minh mã địa danh và quan hệ phường/xã thuộc tỉnh/thành.
        // Tên địa danh được lấy từ danh mục.
        try {
            $location = $this->locationService->resolve(
                trim((string) $source['province_id']),
                trim((string) $source['ward_id'])
            );
        } catch (ValidationException $exception) {
            if ($usesSavedAddress) {
                $this->fail(
                    'shipping_address_id',
                    'Tỉnh/thành hoặc phường/xã của địa chỉ đã lưu không còn hợp lệ. Vui lòng cập nhật địa chỉ.'
                );
            }

            throw $exception;
        }

        return [
            'receiver_name' => trim((string) $source['receiver_name']),
            'receiver_phone' => trim((string) $source['receiver_phone']),

            'province' => $location['province'],
            'province_id' => $location['province_id'],

            'ward' => $location['ward'],
            'ward_id' => $location['ward_id'],

            'district' => null,
            'district_id' => null,

            'address_detail' => trim((string) $source['address_detail']),
        ];
    }

    private function assertRegion(
        DeliveryMethod $method,
        array $address
    ): void {
        // NULL là phương thức không giới hạn vùng.
        if ($method->region === null) {
            return;
        }

        $regions = config('shipping.regions', []);
        $allowed = $regions[$method->region] ?? null;
        $provinceId = $address['province_id'] ?? null;

        if (!is_array($allowed)) {
            $this->fail(
                'delivery_id',
                'Vùng giao hàng chưa được cấu hình.'
            );
        }

        if (
            !$provinceId
            || !in_array(
                (string) $provinceId,
                array_map('strval', $allowed),
                true
            )
        ) {
            $this->fail(
                'delivery_id',
                'Phương thức này không phục vụ địa chỉ đã chọn.'
            );
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([
            $field => [$message],
        ]);
    }
}
