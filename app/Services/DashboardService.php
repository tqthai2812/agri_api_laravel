<?php

namespace App\Services;

use App\Contracts\Repositories\DashboardRepositoryInterface;
use App\Contracts\Services\DashboardServiceInterface;
use App\Models\Order;
use App\Support\DashboardPeriod;
use App\Support\OrderMoney;
use App\Support\OrderPaymentState;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class DashboardService implements DashboardServiceInterface
{
    public function __construct(protected DashboardRepositoryInterface $repository) {}

    public function overview(DashboardPeriod $period): array
    {
        $rawCounts = $this->repository->createdStatusCounts($period);
        $counts = [];
        foreach (['pending', 'confirmed', 'shipping', 'completed', 'cancelled'] as $status) {
            $counts[$status] = (int) ($rawCounts[$status] ?? 0);
        }
        $counts['all'] = array_sum($rawCounts);

        $summary = [
            'completed_orders' => 0,
            'gross_sales' => 0,
            'discount' => 0,
            'net_sales' => 0,
            'shipping_charged' => 0,
            'order_total' => 0,
            'known_cost' => 0,
            'profit_ready_orders' => 0,
            'profit_ready_sales' => 0,
            'profit_ready_cost' => 0,
        ];
        $quality = [
            'invalid_header_orders' => 0,
            'missing_cost_orders' => 0,
            'inconsistent_sales_orders' => 0,
            'empty_orders' => 0,
        ];
        $settlement = [
            'paid' => ['count' => 0, 'amount' => 0],
            'cod_uncollected' => ['count' => 0, 'amount' => 0],
            'review' => ['count' => 0, 'amount' => 0],
        ];
        $days = [];
        foreach ($period->days() as $date) {
            $days[$date] = [
                'date' => $date,
                'completed_orders' => 0,
                'net_sales' => 0,
                'known_cost' => 0,
                'profit_ready_sales' => 0,
                'profit_ready_cost' => 0,
                'sales_complete' => true,
                'profit_complete' => true
            ];
        }
        $products = [];

        foreach ($this->repository->completedOrders($period) as $order) {
            $date = $order->completed_at->copy()->timezone(DashboardPeriod::TIMEZONE)->toDateString();
            if (!isset($days[$date])) continue;
            $day = &$days[$date];
            $day['completed_orders']++;
            $summary['completed_orders']++;

            $total = $this->money($order->total_payment);
            $shipping = $this->money($order->delivery_cost);
            $discount = $this->money($order->discount_amount);
            $headerValid = $total !== null && $shipping !== null && $discount !== null && $total >= $shipping;
            $net = $headerValid ? $total - $shipping : null;
            if ($headerValid) {
                $this->add($summary['gross_sales'], OrderMoney::add($net, $discount));
                $this->add($summary['discount'], $discount);
                $this->add($summary['net_sales'], $net);
                $this->add($summary['shipping_charged'], $shipping);
                $this->add($summary['order_total'], $total);
                $this->add($day['net_sales'], $net);
            } else {
                $quality['invalid_header_orders']++;
                $day['sales_complete'] = false;
            }

            $itemsValid = $order->items->isNotEmpty();
            $costComplete = $itemsValid;
            if (!$itemsValid) $quality['empty_orders']++;
            $itemNet = $itemDiscount = $itemGross = $quantity = $orderCost = 0;

            foreach ($order->items as $item) {
                $price = $this->money($item->price);
                $rowDiscount = $this->money($item->discount_amount);
                $rowNet = $this->money($item->net_sales_amount);
                $cost = $this->money($item->cost_total);
                $qty = (int) $item->quantity;
                $gross = null;
                if ($qty > 0 && $price !== null) {
                    try {
                        $gross = OrderMoney::multiply($price, $qty);
                    } catch (ValidationException) { /* Mark inconsistent below. */
                    }
                }
                $rowValid = $gross !== null && $rowDiscount !== null && $rowNet !== null
                    && $rowDiscount <= $gross && $rowNet === $gross - $rowDiscount;
                if (!$rowValid) $itemsValid = false;
                else {
                    $this->add($itemGross, $gross);
                    $this->add($itemDiscount, $rowDiscount);
                    $this->add($itemNet, $rowNet);
                }
                if ($qty > 0) $quantity += $qty;
                if ($cost === null) $costComplete = false;
                else {
                    $this->add($orderCost, $cost);
                    $this->add($summary['known_cost'], $cost);
                    $this->add($day['known_cost'], $cost);
                }
                $product = $item->package?->variant?->product;
                $key = $product ? 'product:' . $product->id : 'package:' . ($item->package_id ?? 'item-' . $item->id);
                if (!isset($products[$key])) {
                    $products[$key] = [
                        'key' => $key,
                        'product_id' => $product?->id,
                        'name' => $item->product_name ?: ($product?->product_name ?: 'Sản phẩm không còn liên kết'),
                        'quantity' => 0,
                        'order_count' => 0,
                        'last_order_id' => null,
                        'net_sales' => 0,
                        'sales_complete' => true,
                    ];
                }
                $products[$key]['quantity'] += max(0, $qty);
                // Items of an order are processed together: no growing set of all order IDs is needed.
                if ($products[$key]['last_order_id'] !== $order->id) {
                    $products[$key]['order_count']++;
                    $products[$key]['last_order_id'] = $order->id;
                }
                if ($rowValid) $this->add($products[$key]['net_sales'], $rowNet);
                else $products[$key]['sales_complete'] = false;
            }
            $salesConsistent = $headerValid && $itemsValid
                && $itemNet === $net && $itemDiscount === $discount
                && $itemGross === OrderMoney::add($net, $discount)
                && $quantity === (int) $order->total_quantity;
            if (!$salesConsistent) {
                $quality['inconsistent_sales_orders']++;
                // Do not report exact product revenues from an order whose snapshots do not reconcile.
                foreach ($order->items as $item) {
                    $product = $item->package?->variant?->product;
                    $key = $product ? 'product:' . $product->id : 'package:' . ($item->package_id ?? 'item-' . $item->id);
                    $products[$key]['sales_complete'] = false;
                }
            }
            if (!$costComplete) $quality['missing_cost_orders']++;
            if ($salesConsistent && $costComplete) {
                $summary['profit_ready_orders']++;
                $this->add($summary['profit_ready_sales'], $net);
                $this->add($summary['profit_ready_cost'], $orderCost);
                $this->add($day['profit_ready_sales'], $net);
                $this->add($day['profit_ready_cost'], $orderCost);
            } else $day['profit_complete'] = false;

            $state = $this->settlement($order, $headerValid);
            $settlement[$state]['count']++;
            if ($total === null) $settlement[$state]['amount'] = null;
            elseif ($settlement[$state]['amount'] !== null) $this->add($settlement[$state]['amount'], $total);
            unset($day);
        }

        $completeProfit = $summary['profit_ready_orders'] === $summary['completed_orders'];
        $moneyKeys = ['gross_sales', 'discount', 'net_sales', 'shipping_charged', 'order_total'];
        foreach ($moneyKeys as $key) {
            $summary[$key] = $quality['invalid_header_orders'] === 0 ? $this->decimal($summary[$key]) : null;
        }
        $summary['cost_total'] = $quality['missing_cost_orders'] === 0 ? $this->decimal($summary['known_cost']) : null;
        $summary['known_cost'] = $this->decimal($summary['known_cost']);
        $knownProfit = $summary['profit_ready_sales'] - $summary['profit_ready_cost'];
        $summary['gross_profit'] = $completeProfit ? $this->decimal($knownProfit) : null;
        $summary['known_gross_profit'] = $this->decimal($knownProfit);
        $summary['profit_ready_sales'] = $this->decimal($summary['profit_ready_sales']);
        $summary['profit_ready_cost'] = $this->decimal($summary['profit_ready_cost']);

        $series = array_map(function ($day) {
            return [
                'date' => $day['date'],
                'completed_orders' => $day['completed_orders'],
                'net_sales' => $day['sales_complete'] ? $this->decimal($day['net_sales']) : null,
                'gross_profit' => $day['profit_complete']
                    ? $this->decimal($day['profit_ready_sales'] - $day['profit_ready_cost']) : null,
            ];
        }, array_values($days));
        usort($products, fn($a, $b) => ($b['quantity'] <=> $a['quantity']) ?: strcmp($a['key'], $b['key']));
        $topProducts = array_map(function ($product) {
            $product['net_sales'] = $product['sales_complete'] ? $this->decimal($product['net_sales']) : null;
            unset($product['last_order_id'], $product['sales_complete']);
            return $product;
        }, array_slice($products, 0, 10));
        foreach ($settlement as &$state) {
            $state['amount'] = $state['amount'] === null ? null : $this->decimal($state['amount']);
        }
        unset($state);
        $quality['undated_completed_all_time'] = $this->repository->undatedCompletedCount();

        return [
            'period' => ['date_from' => $period->from, 'date_to' => $period->to, 'timezone' => DashboardPeriod::TIMEZONE],
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'order_counts' => $counts,
            'summary' => $summary,
            'settlement' => $settlement,
            'quality' => $quality,
            'daily' => $series,
            'top_products' => $topProducts,
            'recent_orders' => $this->repository->recentOrders($period)->map(fn($order) => [
                'id' => $order->id,
                'code' => 'DH' . str_pad((string) $order->id, 6, '0', STR_PAD_LEFT),
                'order_status' => $order->order_status,
                'payment_method' => $order->payment_method,
                'total_payment' => (string) $order->total_payment,
                'created_at' => $order->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    private function settlement(Order $order, bool $headerValid): string
    {
        // OrderPaymentState.is_paid means "has a paid record"; it is not proof of a matching receipt.
        if (!$headerValid) return 'review';
        $paid = $order->payments->where('status', 'paid');
        foreach ($paid as $payment) {
            if ($this->money($payment->amount) === null) return 'review';
        }
        $state = OrderPaymentState::of($order, $order->payments);
        if ((bool) $state['payment_review']) return 'review';
        if (
            $paid->count() === 1
            && $paid->first()->payment_method === $order->payment_method
            && $this->money($paid->first()->amount) === $this->money($order->total_payment)
        ) {
            return 'paid';
        }
        if ($paid->isEmpty() && $order->payment_method === Order::PAYMENT_COD) return 'cod_uncollected';
        return 'review';
    }

    private function money(mixed $value): ?int
    {
        if (!is_string($value) && !is_int($value)) return null;
        try {
            return OrderMoney::cents($value);
        } catch (ValidationException) {
            return null;
        }
    }

    private function add(int &$total, int $value): void
    {
        $total = OrderMoney::add($total, $value);
    }

    private function decimal(int $cents): string
    {
        // Gross profit can be negative; OrderMoney.decimal only accepts nonnegative amounts.
        return ($cents < 0 ? '-' : '') . OrderMoney::decimal(abs($cents));
    }
}
