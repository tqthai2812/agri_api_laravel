<?php

use App\Models\Order;
use App\Services\VnpayService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('vnpay:reconcile', function () {
    $service = app(VnpayService::class);
    Order::where('payment_method', 'VNPAY')->where('order_status', 'pending')
        ->whereNotNull('payment_expires_at')->where('payment_expires_at', '<=', now())
        ->whereNull('payment_review')->whereDoesntHave('payments', fn($q) => $q->where('status', 'paid'))
        ->select('id')->chunkById(100, function ($orders) use ($service) {
            foreach ($orders as $order) {
                try {
                    $service->reconcile($order->id);
                } catch (Throwable $error) {
                    Log::error('Lỗi đối chiếu đơn VNPAY.', ['order_id' => $order->id, 'error_type' => $error::class]);
                }
            }
        });
    $this->info('Đã hoàn tất lượt đối chiếu VNPAY.');
});

Schedule::command('vnpay:reconcile')->everyMinute()->withoutOverlapping(30);
