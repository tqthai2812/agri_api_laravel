<?php

namespace App\Http\Controllers\Client\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Payment;
use App\Services\VnpayService;
use App\Support\OrderMoney;
use App\Support\OrderRelations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VnpayController extends Controller
{
    public function __construct(private VnpayService $vnpay) {}

    private function rawQuery(Request $request): array
    {
        // Tránh middleware trim chuỗi làm thay đổi dữ liệu đã ký.
        parse_str((string) $request->server('QUERY_STRING'), $data);
        return array_filter($data, fn($key) => str_starts_with($key, 'vnp_'), ARRAY_FILTER_USE_KEY);
    }

    // public function ipn(Request $request)
    // {
    //     try {
    //         $result = $this->vnpay->ipn($this->rawQuery($request));
    //     } catch (\Throwable $error) {
    //         Log::error('Lỗi xử lý IPN VNPAY.', ['error_type' => $error::class]);
    //         $result = ['RspCode' => '99', 'Message' => 'Processing error'];
    //     }
    //     return response()->json($result)->header('Cache-Control', 'no-store');
    // }

    public function ipn(Request $request)
    {
        $data = $this->rawQuery($request);

        Log::info('VNPAY IPN RECEIVED', [
            'data' => $data,
            'ip' => $request->ip(),
        ]);

        try {
            $result = $this->vnpay->ipn($data);
        } catch (\Throwable $error) {
            Log::error('Lỗi xử lý IPN VNPAY.', [
                'error_type' => $error::class,
                'message' => $error->getMessage(),
            ]);

            $result = [
                'RspCode' => '99',
                'Message' => 'Processing error',
            ];
        }

        Log::info('VNPAY IPN RESPONSE', [
            'result' => $result,
        ]);

        return response()
            ->json($result)
            ->header('Cache-Control', 'no-store');
    }

    // public function returned(Request $request)
    // {
    //     $frontend = rtrim(config('vnpay.frontend_url'), '/');
    //     $data = $this->rawQuery($request);

    //     if (!$this->vnpay->verified($data)) return redirect()->away($frontend . '/my-orders?payment_return=invalid');
    //     $payment = Payment::where('payment_method', 'VNPAY')
    //         ->where('merchant_reference', $data['vnp_TxnRef'] ?? '')->first();
    //     if (
    //         !$payment || !is_string($data['vnp_Amount'] ?? null)
    //         || !preg_match('/^[0-9]{1,12}$/D', $data['vnp_Amount'])
    //         || (int) $data['vnp_Amount'] !== OrderMoney::cents($payment->amount)
    //     ) {
    //         return redirect()->away($frontend . '/my-orders?payment_return=invalid');
    //     }
    //     // Chỉ điều hướng; kết quả trên trình duyệt không cập nhật tiền/đơn hàng.
    //     return redirect()->away($frontend . '/account/orders/' . $payment->order_id . '?payment_return=1');
    // }

    public function returned(Request $request)
    {
        $frontend = rtrim(config('vnpay.frontend_url'), '/');

        $data = $this->rawQuery($request);

        Log::info('VNPAY RETURN RECEIVED', [
            'data' => $data,
        ]);

        if (!$this->vnpay->verified($data)) {
            Log::warning('VNPAY RETURN INVALID SIGNATURE', [
                'data' => $data,
            ]);

            return redirect()->away(
                $frontend . '/my-orders?payment_return=invalid'
            );
        }

        $payment = Payment::where('payment_method', 'VNPAY')
            ->where('merchant_reference', $data['vnp_TxnRef'] ?? '')
            ->first();

        if (
            !$payment
            || !is_string($data['vnp_Amount'] ?? null)
            || !preg_match('/^[0-9]{1,12}$/D', $data['vnp_Amount'])
            || (int) $data['vnp_Amount'] !== OrderMoney::cents($payment->amount)
        ) {
            return redirect()->away(
                $frontend . '/my-orders?payment_return=invalid'
            );
        }

        Log::info('VNPAY RETURN VERIFIED', [
            'payment_id' => $payment->id,
            'order_id' => $payment->order_id,
            'response_code' => $data['vnp_ResponseCode'] ?? null,
            'transaction_status' => $data['vnp_TransactionStatus'] ?? null,
            'transaction_no' => $data['vnp_TransactionNo'] ?? null,
        ]);

        return redirect()->away(
            $frontend
                . '/account/orders/'
                . $payment->order_id
                . '?payment_return=1'
        );
    }

    public function pay(Request $request, Order $order)
    {
        return response()->json(['data' => [
            'payment_redirect_url' => $this->vnpay->pay($order->id, (int) $request->user()->id, $request->ip()),
        ]]);
    }

    public function check(Request $request, Order $order)
    {
        abort_unless((int) $order->user_id === (int) $request->user()->id, 404);
        abort_unless($order->payment_method === 'VNPAY', 422, 'Đơn không dùng VNPAY.');
        $this->vnpay->reconcile($order->id);
        return response()->json([
            'message' => 'Đã kiểm tra trạng thái. Giao dịch chưa rõ kết quả sẽ tiếp tục được đối chiếu.',
            'data' => new OrderResource($order->fresh()->load(OrderRelations::detail())->loadCount('items')),
        ]);
    }
}
