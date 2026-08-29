<?php

namespace App\Services;

use App\Contracts\Repositories\CartRepositoryInterface;
use App\Contracts\Services\CheckoutServiceInterface;
use App\Http\Resources\CartResource;
use App\Http\Resources\OrderResource;
use App\Models\DeliveryMethod;
use App\Models\Discount;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderHistory;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ProductPackage;
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
            'delivery_methods' => $deliveryMethods->map(fn($method) => [
                'id' => $method->id,
                'name' => $method->name,
                'description' => $method->description,
                'base_price' => (float) $method->base_price,
                'min_order_amount' => (float) $method->min_order_amount,
                'region' => $method->region,
                'is_default' => (bool) $method->is_default,
            ]),
            'payment_methods' => [
                [
                    'value' => Order::PAYMENT_COD,
                    'label' => 'Thanh toán khi nhận hàng',
                ],
                [
                    'value' => Order::PAYMENT_VNPAY,
                    'label' => 'VNPay',
                ],
            ],
        ];
    }

    public function preview(User $user, array $data): array
    {
        return $this->buildSummary($user, $data);
    }

    public function checkout(User $user, array $data): array
    {
        return DB::transaction(function () use ($user, $data) {
            $summary = $this->buildSummary($user, $data, true);

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

            OrderAddress::create([
                'order_id' => $order->id,
                'receiver_name' => $data['receiver_name'],
                'receiver_phone' => $data['receiver_phone'],
                'province' => $data['province'],
                'district' => $data['district'],
                'ward' => $data['ward'],
                'province_id' => $data['province_id'] ?? null,
                'district_id' => $data['district_id'] ?? null,
                'ward_id' => $data['ward_id'] ?? null,
                'address_detail' => $data['address_detail'],
            ]);

            foreach ($summary['cart']->items as $item) {
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
                    'quantity' => $item->quantity,
                    'price' => $package->price,
                ]);

                $package->decrement('quantity_available', (int) $item->quantity);

                InventoryTransaction::create([
                    'package_id' => $package->id,
                    'quantity_change' => -abs((int) $item->quantity),
                    'transaction_type' => 'export',
                    'note' => 'Xuất kho cho đơn hàng DH' . str_pad($order->id, 6, '0', STR_PAD_LEFT),
                    'performed_by' => $user->id,
                ]);
            }

            Payment::create([
                'order_id' => $order->id,
                'payment_method' => $data['payment_method'],
                'transaction_id' => null,
                'amount' => $summary['total_payment'],
                'status' => 'pending',
                'paid_at' => null,
                'failed_reason' => null,
            ]);

            OrderHistory::create([
                'order_id' => $order->id,
                'order_status' => Order::STATUS_PENDING,
                'note' => 'Khách hàng đặt đơn.',
                'created_by' => $user->id,
            ]);

            if (!empty($summary['discount'])) {
                Discount::where('id', $summary['discount']['id'])->increment('used_count');
            }

            $this->cartRepository->clearCart($summary['cart']);

            $order = $order->fresh([
                'user:id,name,email,phone_number',
                'deliveryMethod:id,name,description,base_price,min_order_amount,region',
                'discount:id,discount_code,discount_description,discount_percent,max_discount_amount,min_order_value',
                'payment:id,order_id,payment_method,transaction_id,amount,status,paid_at,failed_reason',
                'orderAddress',
                'histories.creator:id,name,email',
                'items.package.variant.product.images',
            ])->loadCount('items');

            return [
                'order' => new OrderResource($order),
            ];
        });
    }

    private function buildSummary(User $user, array $data, bool $lockPackages = false): array
    {
        $cart = $this->cartRepository->getCartWithItems($user->id);

        if ($cart->items->isEmpty()) {
            throw new RuntimeException('Giỏ hàng đang trống.');
        }

        $packageIds = $cart->items->pluck('package_id')->all();

        $packagesQuery = ProductPackage::query()
            ->whereIn('id', $packageIds);

        if ($lockPackages) {
            $packagesQuery->lockForUpdate();
        }

        $packages = $packagesQuery->get()->keyBy('id');

        $subtotal = 0;
        $totalQuantity = 0;

        foreach ($cart->items as $item) {
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

        if ($subtotal < (float) $deliveryMethod->min_order_amount) {
            throw new RuntimeException('Đơn hàng chưa đạt giá trị tối thiểu để dùng phương thức giao hàng này.');
        }

        $discount = null;
        $discountAmount = 0;

        if (!empty($data['discount_code'])) {
            $discount = $this->validateDiscount($user, $data['discount_code'], $subtotal);

            $discountAmount = min(
                $subtotal * ((int) $discount->discount_percent / 100),
                (float) $discount->max_discount_amount
            );
        }

        $deliveryCost = (float) $deliveryMethod->base_price;
        $totalPayment = max(0, $subtotal + $deliveryCost - $discountAmount);

        return [
            'cart' => $cart,
            'cart_data' => new CartResource($cart),

            'subtotal' => $subtotal,
            'total_quantity' => $totalQuantity,

            'delivery_method' => [
                'id' => $deliveryMethod->id,
                'name' => $deliveryMethod->name,
                'base_price' => (float) $deliveryMethod->base_price,
                'min_order_amount' => (float) $deliveryMethod->min_order_amount,
                'region' => $deliveryMethod->region,
            ],

            'delivery_cost' => $deliveryCost,

            'discount' => $discount ? [
                'id' => $discount->id,
                'discount_code' => $discount->discount_code,
                'discount_description' => $discount->discount_description,
                'discount_percent' => (int) $discount->discount_percent,
            ] : null,

            'discount_amount' => $discountAmount,
            'total_payment' => $totalPayment,
        ];
    }

    private function validateDiscount(User $user, string $code, float $subtotal): Discount
    {
        $discount = Discount::query()
            ->where('discount_code', strtoupper(trim($code)))
            ->first();

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

        if ($discount->usage_limit !== null && (int) $discount->used_count >= (int) $discount->usage_limit) {
            throw new RuntimeException('Mã giảm giá đã hết lượt sử dụng.');
        }

        if ($subtotal < (float) $discount->min_order_value) {
            throw new RuntimeException('Đơn hàng chưa đạt giá trị tối thiểu để áp dụng mã giảm giá.');
        }

        return $discount;
    }
}
