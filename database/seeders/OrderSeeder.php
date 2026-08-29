<?php

namespace Database\Seeders;

use App\Models\DeliveryMethod;
use App\Models\Discount;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderHistory;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ProductPackage;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class OrderSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            Order::where('note', 'like', 'Đơn hàng mẫu%')->delete();

            $customer = User::firstOrCreate(
                ['email' => 'customer@gmail.com'],
                [
                    'name' => 'Khách hàng mẫu',
                    'password' => Hash::make('12345678'),
                    'role' => 'customer',
                    'phone_number' => '0909000001',
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );

            $admin = User::where('role', 'admin')->first() ?? User::first();

            $delivery = DeliveryMethod::where('is_active', true)->first();

            if (!$delivery) {
                $this->command?->warn('Chưa có phương thức giao hàng. Hãy chạy DeliveryMethodSeeder trước.');
                return;
            }

            $packages = ProductPackage::query()
                ->with(['variant.product'])
                ->take(3)
                ->get();

            if ($packages->isEmpty()) {
                $this->command?->warn('Chưa có product_packages. Hãy seed sản phẩm trước.');
                return;
            }

            $discount = Discount::where('is_active', true)->first();

            $samples = [
                [
                    'status' => Order::STATUS_PENDING,
                    'payment_method' => Order::PAYMENT_COD,
                    'note' => 'Đơn hàng mẫu - chờ xác nhận',
                ],
                [
                    'status' => Order::STATUS_CONFIRMED,
                    'payment_method' => Order::PAYMENT_COD,
                    'note' => 'Đơn hàng mẫu - đã xác nhận',
                ],
                [
                    'status' => Order::STATUS_SHIPPING,
                    'payment_method' => Order::PAYMENT_VNPAY,
                    'note' => 'Đơn hàng mẫu - đang giao',
                ],
            ];

            foreach ($samples as $index => $sample) {
                $selectedPackages = $packages->take(min(2, $packages->count()));

                $subtotal = 0;
                $totalQuantity = 0;

                foreach ($selectedPackages as $package) {
                    $quantity = $index + 1;
                    $subtotal += (float) $package->price * $quantity;
                    $totalQuantity += $quantity;
                }

                $discountAmount = 0;

                if ($discount && $subtotal >= (float) $discount->min_order_value) {
                    $discountAmount = min(
                        $subtotal * ((int) $discount->discount_percent / 100),
                        (float) $discount->max_discount_amount
                    );
                }

                $deliveryCost = (float) $delivery->base_price;
                $totalPayment = max(0, $subtotal + $deliveryCost - $discountAmount);

                $order = Order::create([
                    'user_id' => $customer->id,
                    'note' => $sample['note'],
                    'delivery_id' => $delivery->id,
                    'discount_amount' => $discountAmount,
                    'discount_id' => $discount?->id,
                    'delivery_cost' => $deliveryCost,
                    'total_quantity' => $totalQuantity,
                    'total_payment' => $totalPayment,
                    'payment_method' => $sample['payment_method'],
                    'order_status' => $sample['status'],
                ]);

                OrderAddress::create([
                    'order_id' => $order->id,
                    'receiver_name' => 'Trần Quốc Thái',
                    'receiver_phone' => '0909000001',
                    'province' => 'Cần Thơ',
                    'district' => 'Ninh Kiều',
                    'ward' => 'Tân An',
                    'province_id' => null,
                    'district_id' => null,
                    'ward_id' => null,
                    'address_detail' => 'Đường mẫu số ' . ($index + 1),
                ]);

                foreach ($selectedPackages as $package) {
                    OrderItem::create([
                        'order_id' => $order->id,
                        'package_id' => $package->id,
                        'quantity' => $index + 1,
                        'price' => $package->price,
                    ]);
                }

                Payment::create([
                    'order_id' => $order->id,
                    'payment_method' => $sample['payment_method'],
                    'transaction_id' => $sample['payment_method'] === Order::PAYMENT_VNPAY
                        ? 'VNPAY_SAMPLE_' . $order->id
                        : null,
                    'amount' => $totalPayment,
                    'status' => $sample['status'] === Order::STATUS_COMPLETED ? 'paid' : 'pending',
                    'paid_at' => null,
                    'failed_reason' => null,
                ]);

                OrderHistory::create([
                    'order_id' => $order->id,
                    'order_status' => $sample['status'],
                    'note' => 'Tạo đơn hàng mẫu.',
                    'created_by' => $admin?->id ?? $customer->id,
                ]);
            }
        });
    }
}