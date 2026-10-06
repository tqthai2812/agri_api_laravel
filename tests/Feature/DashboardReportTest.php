<?php

namespace Tests\Feature;

use App\Contracts\Repositories\DashboardRepositoryInterface;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Services\DashboardService;
use App\Support\DashboardPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as ModelCollection;
use Illuminate\Support\LazyCollection;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

// Uses in-memory models and a mocked repository. Never migrates, truncates or writes the database.
class DashboardReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
    }

    private function order(int $id = 1, array $attributes = [], ?string $cost = '60000.00'): Order
    {
        $order = new Order();
        $order->forceFill(array_merge([
            'id' => $id,
            'order_status' => 'completed',
            'payment_method' => 'COD',
            'payment_review' => null,
            'payment_expires_at' => null,
            'total_payment' => '120000.00',
            'delivery_cost' => '30000.00',
            'discount_amount' => '10000.00',
            'total_quantity' => 2,
            'completed_at' => CarbonImmutable::parse('2026-10-03 17:30:00', 'UTC'),
        ], $attributes));
        $item = new OrderItem();
        $item->forceFill([
            'id' => $id * 10,
            'order_id' => $id,
            'package_id' => 7,
            'product_name' => 'Sản phẩm thử',
            'quantity' => 2,
            'price' => '50000.00',
            'discount_amount' => '10000.00',
            'net_sales_amount' => '90000.00',
            'cost_total' => $cost,
        ]);
        $item->setRelation('package', null);
        return $order->setRelation('items', new ModelCollection([$item]))
            ->setRelation('payments', new ModelCollection());
    }

    private function payment(string $status = 'paid', string $amount = '120000.00', string $method = 'VNPAY'): Payment
    {
        return (new Payment())->forceFill(['status' => $status, 'amount' => $amount, 'payment_method' => $method]);
    }

    private function report(array $orders = [], int $undated = 0): array
    {
        $repository = Mockery::mock(DashboardRepositoryInterface::class);
        $repository->shouldReceive('createdStatusCounts')->once()->andReturn(['pending' => 1, 'cancelled' => 2]);
        $repository->shouldReceive('completedOrders')->once()->andReturn(new LazyCollection($orders));
        $repository->shouldReceive('recentOrders')->once()->andReturn(collect());
        $repository->shouldReceive('undatedCompletedCount')->once()->andReturn($undated);
        return (new DashboardService($repository))->overview(new DashboardPeriod('2026-10-04', '2026-10-05'));
    }

    public function test_discount_shipping_and_uncollected_cod_are_not_confused(): void
    {
        $data = $this->report([$this->order()]);
        $this->assertSame('100000.00', $data['summary']['gross_sales']);
        $this->assertSame('10000.00', $data['summary']['discount']);
        $this->assertSame('90000.00', $data['summary']['net_sales']);
        $this->assertSame('30000.00', $data['summary']['shipping_charged']);
        $this->assertSame('120000.00', $data['summary']['order_total']);
        $this->assertSame('30000.00', $data['summary']['gross_profit']);
        $this->assertSame(['count' => 1, 'amount' => '120000.00'], $data['settlement']['cod_uncollected']);
        $this->assertSame(0, $data['settlement']['paid']['count']);
        $this->assertSame(3, $data['order_counts']['all']);
        $this->assertSame(1, $data['summary']['completed_orders']);
        $this->assertSame('2026-10-04', $data['daily'][0]['date']);
        $this->assertSame(1, $data['daily'][0]['completed_orders']);
        $this->assertSame('0.00', $data['daily'][1]['gross_profit']);
    }

    public function test_a_failed_retry_does_not_hide_a_paid_receipt_or_duplicate_revenue(): void
    {
        $order = $this->order(attributes: ['payment_method' => 'VNPAY']);
        $order->setRelation('payments', new ModelCollection([
            $this->payment('failed'),
            $this->payment(),
            $this->payment('pending'),
        ]));
        $data = $this->report([$order]);
        $this->assertSame(1, $data['settlement']['paid']['count']);
        $this->assertSame('120000.00', $data['settlement']['paid']['amount']);
        $this->assertSame('90000.00', $data['summary']['net_sales']);
    }

    public function test_duplicate_mismatched_or_flagged_payments_require_review(): void
    {
        $duplicate = $this->order(1, ['payment_method' => 'VNPAY']);
        $duplicate->setRelation('payments', new ModelCollection([$this->payment(), $this->payment()]));
        $wrongAmount = $this->order(2, ['payment_method' => 'VNPAY']);
        $wrongAmount->setRelation('payments', new ModelCollection([$this->payment(amount: '119999.00')]));
        $wrongMethod = $this->order(3, ['payment_method' => 'VNPAY']);
        $wrongMethod->setRelation('payments', new ModelCollection([$this->payment(method: 'COD')]));
        $flagged = $this->order(4, ['payment_method' => 'VNPAY', 'payment_review' => 'Cần đối chiếu']);
        $flagged->setRelation('payments', new ModelCollection([$this->payment()]));
        $noReceipt = $this->order(5, ['payment_method' => 'VNPAY']);
        $data = $this->report([$duplicate, $wrongAmount, $wrongMethod, $flagged, $noReceipt]);
        $this->assertSame(0, $data['settlement']['paid']['count']);
        $this->assertSame(['count' => 5, 'amount' => '600000.00'], $data['settlement']['review']);
    }

    public function test_unknown_cost_is_not_zero_and_known_profit_uses_a_matching_subset(): void
    {
        $data = $this->report([$this->order(1), $this->order(2, cost: null)]);
        $this->assertNull($data['summary']['cost_total']);
        $this->assertNull($data['summary']['gross_profit']);
        $this->assertNull($data['daily'][0]['gross_profit']);
        $this->assertSame(1, $data['quality']['missing_cost_orders']);
        $this->assertSame(1, $data['summary']['profit_ready_orders']);
        $this->assertSame('30000.00', $data['summary']['known_gross_profit']);
        $this->assertSame('90000.00', $data['summary']['profit_ready_sales']);
        $this->assertSame('180000.00', $data['summary']['net_sales']);
    }

    public function test_zero_cost_and_negative_profit_are_valid_values(): void
    {
        $zero = $this->report([$this->order(cost: '0.00')]);
        $this->assertSame('0.00', $zero['summary']['cost_total']);
        $this->assertSame('90000.00', $zero['summary']['gross_profit']);
        $loss = $this->report([$this->order(cost: '100000.00')]);
        $this->assertSame('-10000.00', $loss['summary']['gross_profit']);
        $this->assertSame('-10000.00', $loss['daily'][0]['gross_profit']);
    }

    public function test_missing_or_inconsistent_line_snapshots_do_not_produce_a_profit(): void
    {
        $order = $this->order();
        $order->items->first()->net_sales_amount = '99999.00';
        $data = $this->report([$order]);
        $this->assertSame(1, $data['quality']['inconsistent_sales_orders']);
        $this->assertNull($data['summary']['gross_profit']);
        $this->assertNull($data['top_products'][0]['net_sales']);
        $this->assertSame('90000.00', $data['summary']['net_sales']);
    }

    public function test_inconsistent_header_and_empty_orders_are_reported(): void
    {
        $badHeader = $this->order(1, ['total_payment' => '20000.00']);
        $empty = $this->order(2)->setRelation('items', new ModelCollection());
        $data = $this->report([$badHeader, $empty], undated: 3);
        $this->assertNull($data['summary']['net_sales']);
        $this->assertNull($data['summary']['gross_profit']);
        $this->assertSame(1, $data['quality']['invalid_header_orders']);
        $this->assertSame(1, $data['quality']['empty_orders']);
        $this->assertSame(3, $data['quality']['undated_completed_all_time']);
        $this->assertSame(1, $data['settlement']['review']['count']);
    }

    public function test_multiple_items_of_one_product_count_the_order_once(): void
    {
        $order = $this->order();
        $first = $order->items->first();
        $first->quantity = 1;
        $first->discount_amount = '5000.00';
        $first->net_sales_amount = '45000.00';
        $first->cost_total = '30000.00';
        $second = clone $first;
        $second->id = 11;
        $order->setRelation('items', new ModelCollection([$first, $second]));
        $data = $this->report([$order]);
        $this->assertCount(1, $data['top_products']);
        $this->assertSame(2, $data['top_products'][0]['quantity']);
        $this->assertSame(1, $data['top_products'][0]['order_count']);
        $this->assertSame('90000.00', $data['top_products'][0]['net_sales']);
    }

    public function test_empty_period_has_explicit_zero_values_and_all_days(): void
    {
        $data = $this->report();
        $this->assertSame(0, $data['summary']['completed_orders']);
        $this->assertSame('0.00', $data['summary']['net_sales']);
        $this->assertSame('0.00', $data['summary']['gross_profit']);
        $this->assertCount(2, $data['daily']);
        $this->assertSame([], $data['top_products']);
    }

    public function test_vietnam_date_bounds_are_half_open_and_converted_to_storage_timezone(): void
    {
        $period = new DashboardPeriod('2026-10-04', '2026-10-04');
        $this->assertSame('2026-10-03 17:00:00', $period->storageStart());
        $this->assertSame('2026-10-04 17:00:00', $period->storageEnd());
        $this->assertSame(['2026-10-04'], $period->days());
        $this->assertCount(366, (new DashboardPeriod('2024-01-01', '2024-12-31'))->days());
    }

    public function test_too_long_period_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        new DashboardPeriod('2024-01-01', '2025-01-01');
    }

    public function test_invalid_calendar_date_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        new DashboardPeriod('2026-02-30', '2026-03-01');
    }
}
