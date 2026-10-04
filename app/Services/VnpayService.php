<?php

namespace App\Services;

use App\Contracts\Services\OrderStockServiceInterface;
use App\Models\Order;
use App\Models\OrderHistory;
use App\Models\Payment;
use App\Support\OrderMoney;
use App\Support\OrderPaymentState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VnpayService
{
    public function __construct(protected OrderStockServiceInterface $stock) {}

    public function enabled(): bool
    {
        return config('vnpay.enabled') && config('vnpay.tmn_code')
            && config('vnpay.hash_secret') && config('vnpay.return_url');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['payment' => [$message]]);
    }

    private function mac(string $data): string
    {
        if (!config('vnpay.hash_secret')) {
            throw new \RuntimeException('VNPAY chưa có khóa xác thực.');
        }
        return hash_hmac('sha512', $data, config('vnpay.hash_secret'));
    }

    private function encoded(array $data): string
    {
        ksort($data);
        return http_build_query($data, '', '&', PHP_QUERY_RFC1738);
    }

    public function verified(array $data): bool
    {
        $hash = $data['vnp_SecureHash'] ?? null;
        if (!is_string($hash) || !preg_match('/^[a-fA-F0-9]{128}$/D', $hash)) return false;
        unset($data['vnp_SecureHash'], $data['vnp_SecureHashType']);
        foreach ($data as $key => $value) {
            if (!str_starts_with($key, 'vnp_') || !is_string($value)) return false;
        }
        return ($data['vnp_TmnCode'] ?? '') === config('vnpay.tmn_code')
            && hash_equals($this->mac($this->encoded($data)), strtolower($hash));
    }

    // Caller đang giữ khóa đơn hoặc vừa tạo đơn trong cùng transaction.
    public function createAttempt(Order $order, string $ip): Payment
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Thiếu transaction.');
        if (!$this->enabled()) $this->fail('VNPAY chưa được kích hoạt.');
        $deadline = OrderPaymentState::deadline($order);
        if (!$deadline || !$deadline->isFuture()) $this->fail('Đã hết thời hạn thanh toán.');
        $amount = OrderMoney::cents($order->total_payment);
        if ($amount < 100 || $amount % 100 !== 0) {
            $this->fail('VNPAY yêu cầu số tiền dương, nguyên đồng.');
        }
        $reference = str_replace('-', '', (string) Str::uuid());
        $created = now('Asia/Ho_Chi_Minh')->format('YmdHis');
        $params = [
            'vnp_Version' => '2.1.0',
            'vnp_Command' => 'pay',
            'vnp_TmnCode' => config('vnpay.tmn_code'),
            'vnp_Amount' => (string) $amount,
            'vnp_CurrCode' => 'VND',
            'vnp_TxnRef' => $reference,
            'vnp_OrderInfo' => 'Thanh toan don hang ' . $order->id,
            'vnp_OrderType' => 'other',
            'vnp_Locale' => 'vn',
            'vnp_ReturnUrl' => config('vnpay.return_url'),
            'vnp_IpAddr' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '127.0.0.1',
            'vnp_CreateDate' => $created,
            'vnp_ExpireDate' => $deadline->copy()->timezone('Asia/Ho_Chi_Minh')->format('YmdHis'),
        ];
        $query = $this->encoded($params);
        $payment = new Payment();
        // Các trường bổ sung chỉ do server gán, không nhận mass assignment từ client.
        $payment->forceFill([
            'order_id' => $order->id,
            'payment_method' => 'VNPAY',
            'amount' => $order->total_payment,
            'status' => 'pending',
            'merchant_reference' => $reference,
            'gateway_created_at' => $created,
            'payment_url' => config('vnpay.payment_url') . '?' . $query . '&vnp_SecureHash=' . $this->mac($query),
        ])->save();
        return $payment;
    }

    public function pay(int $orderId, int $userId, string $ip): string
    {
        return DB::transaction(function () use ($orderId, $userId, $ip) {
            $order = Order::where('user_id', $userId)->lockForUpdate()->findOrFail($orderId);
            $payments = $order->payments()->orderBy('id')->lockForUpdate()->get();
            if (!$this->enabled()) $this->fail('VNPAY chưa được kích hoạt.');
            if (!OrderPaymentState::of($order, $payments)['can_pay']) {
                $this->fail('Đơn không còn đủ điều kiện thanh toán. Vui lòng tải lại.');
            }
            $pending = $payments->where('payment_method', 'VNPAY')->where('status', 'pending');
            if ($pending->count() > 1) $this->fail('Đơn có nhiều giao dịch chưa rõ kết quả. Cần đối chiếu.');
            if ($pending->isNotEmpty()) {
                $payment = $pending->first();
                if (!$payment->payment_url) $this->fail('Giao dịch cũ cần đối chiếu.');
                return $payment->payment_url;
            }
            return $this->createAttempt($order, $ip)->payment_url;
        }, 3);
    }

    public function ipn(array $data): array
    {
        if (!$this->verified($data)) return ['RspCode' => '97', 'Message' => 'Invalid signature'];
        $payment = Payment::where('payment_method', 'VNPAY')
            ->where('merchant_reference', $data['vnp_TxnRef'] ?? '')->first();
        if (!$payment) return ['RspCode' => '01', 'Message' => 'Order not found'];
        return $this->apply($payment->id, $data, false);
    }

    private function history(Order $order, string $note): void
    {
        OrderHistory::create([
            'order_id' => $order->id,
            'order_status' => $order->order_status,
            'note' => $note,
            'created_by' => null,
        ]);
    }

    private function review(Order $order, string $reason): void
    {
        if (!$order->payment_review) {
            $order->forceFill(['payment_review' => $reason])->save();
            $this->history($order, 'Cần đối chiếu VNPAY: ' . $reason);
        }
    }

    private function apply(int $paymentId, array $data, bool $query): array
    {
        $source = Payment::findOrFail($paymentId);
        return DB::transaction(function () use ($source, $data, $query) {
            $order = Order::lockForUpdate()->findOrFail($source->order_id);
            $payments = $order->payments()->orderBy('id')->lockForUpdate()->get();
            $payment = $payments->firstWhere('id', $source->id);
            if (($data['vnp_TxnRef'] ?? '') !== $payment->merchant_reference) {
                return ['RspCode' => '01', 'Message' => 'Invalid reference'];
            }
            $amount = $data['vnp_Amount'] ?? '';
            if (
                !is_string($amount) || !preg_match('/^[0-9]{1,12}$/D', $amount)
                || (int) $amount !== OrderMoney::cents($payment->amount)
                || OrderMoney::cents($payment->amount) !== OrderMoney::cents($order->total_payment)
            ) {
                $this->review($order, 'Số tiền phản hồi không khớp đơn hàng.');
                return ['RspCode' => '04', 'Message' => 'Invalid amount'];
            }
            $status = (string) ($data['vnp_TransactionStatus'] ?? '');
            $response = (string) ($data['vnp_ResponseCode'] ?? '');
            if (!preg_match('/^[0-9]{2}$/D', $status) || !preg_match('/^[0-9]{2}$/D', $response)) {
                return ['RspCode' => '99', 'Message' => 'Invalid status'];
            }
            if ($payment->status === 'paid') {
                if (
                    $status === '00' && $response === '00'
                    && (string) ($data['vnp_TransactionNo'] ?? '') !== (string) $payment->transaction_id
                ) {
                    $this->review($order, 'Một mã tham chiếu có nhiều mã giao dịch thành công tại VNPAY.');
                }
                return ['RspCode' => '02', 'Message' => 'Already confirmed'];
            }
            if ($status === '00' && $response === '00') {
                $transaction = (string) ($data['vnp_TransactionNo'] ?? '');
                if (!preg_match('/^[0-9]{1,15}$/D', $transaction) || (int) $transaction === 0) {
                    return ['RspCode' => '99', 'Message' => 'Invalid transaction'];
                }
                $duplicate = Payment::where('payment_method', 'VNPAY')
                    ->where('transaction_id', $transaction)->where('id', '!=', $payment->id)->exists();
                if ($duplicate) {
                    $this->review($order, 'Mã giao dịch VNPAY đã được ghi cho giao dịch khác.');
                    return ['RspCode' => '99', 'Message' => 'Transaction conflict'];
                }
                $paidAt = now();
                $payDate = $data['vnp_PayDate'] ?? '';
                if ($payDate !== '') {
                    try {
                        $paidAt = Carbon::createFromFormat('!YmdHis', $payDate, 'Asia/Ho_Chi_Minh');
                        if (!$paidAt || $paidAt->format('YmdHis') !== $payDate) {
                            return ['RspCode' => '99', 'Message' => 'Invalid payment date'];
                        }
                        $paidAt->timezone(config('app.timezone'));
                    } catch (\Throwable) {
                        return ['RspCode' => '99', 'Message' => 'Invalid payment date'];
                    }
                }
                $deadline = OrderPaymentState::deadline($order);
                $late = $order->order_status === 'cancelled'
                    || in_array($payment->status, ['failed', 'expired'], true)
                    || $payments->contains('status', 'paid')
                    || ($deadline && $paidAt->gt($deadline));
                $payment->forceFill([
                    'status' => 'paid',
                    'transaction_id' => $transaction,
                    'paid_at' => $paidAt,
                    'failed_reason' => null,
                ])->save();
                if ($late) $this->review($order, 'Có khoản thanh toán đến sau khi giao dịch đã kết thúc hoặc đã thu tiền.');
                $this->history($order, 'VNPAY xác nhận đã thanh toán. Mã giao dịch ' . $transaction . '.');
                // Không chuyển trạng thái đơn, không tạo phiếu xuất kho.
                return ['RspCode' => '00', 'Message' => 'Confirm success'];
            }
            if (in_array($status, ['04', '05', '06', '07', '09'], true) || (!$query && $response === '07')) {
                $this->review($order, 'VNPAY trả trạng thái cần đối chiếu: ' . $status . '/' . $response . '.');
            } elseif ($status === '02' && ($query || in_array($response, ['09', '10', '11', '12', '13', '24', '51', '65', '75', '79', '99'], true))) {
                if ($payment->status === 'pending') {
                    $payment->forceFill([
                        'status' => 'failed',
                        'failed_reason' => 'VNPAY không thành công. Mã ' . $response . '/' . $status . '.',
                    ])->save();
                    $this->history($order, 'Lần thanh toán VNPAY ' . $payment->merchant_reference . ' không thành công.');
                }
            }
            return ['RspCode' => '00', 'Message' => 'Result recorded'];
        }, 3);
    }

    public function reconcile(int $orderId): void
    {
        $ids = Payment::where('order_id', $orderId)->where('payment_method', 'VNPAY')
            ->where('status', 'pending')->whereNotNull('merchant_reference')->pluck('id');
        foreach ($ids as $id) {
            // Giữ mốc truy vấn trong DB, chống nhiều request cùng truy vấn một giao dịch.
            $payment = DB::transaction(function () use ($orderId, $id) {
                Order::lockForUpdate()->findOrFail($orderId);
                $payment = Payment::lockForUpdate()->findOrFail($id);
                $cooldown = config('vnpay.query_cooldown_seconds', 300);
                if ($payment->status !== 'pending' || ($payment->last_checked_at
                    && Carbon::parse($payment->last_checked_at)->addSeconds($cooldown)->isFuture())) return null;
                $payment->forceFill(['last_checked_at' => now()])->save();
                return $payment;
            }, 3);
            if (!$payment) continue;
            try {
                // HTTP chạy ngoài transaction và ngoài khóa dòng.
                $result = $this->query($payment);
                if (
                    $result && ($result['vnp_ResponseCode'] ?? '') === '00'
                    && ($result['vnp_TransactionType'] ?? '') === '01'
                ) {
                    $this->apply($payment->id, $result, true);
                }
                // 91 (không tìm thấy), lỗi mạng, sai chữ ký: chưa phải bằng chứng chưa thu tiền.
            } catch (\Throwable $error) {
                Log::warning('Không đối chiếu được VNPAY.', ['payment_id' => $id, 'error_type' => $error::class]);
            }
        }
        $this->expire($orderId);
    }

    private function query(Payment $payment): ?array
    {
        $request = [
            'vnp_RequestId' => str_replace('-', '', (string) Str::uuid()),
            'vnp_Version' => '2.1.0',
            'vnp_Command' => 'querydr',
            'vnp_TmnCode' => config('vnpay.tmn_code'),
            'vnp_TxnRef' => $payment->merchant_reference,
            'vnp_TransactionDate' => $payment->gateway_created_at,
            'vnp_CreateDate' => now('Asia/Ho_Chi_Minh')->format('YmdHis'),
            'vnp_IpAddr' => config('vnpay.query_ip'),
            'vnp_OrderInfo' => 'Kiem tra thanh toan don hang ' . $payment->order_id,
        ];
        $request['vnp_SecureHash'] = $this->mac(implode('|', $request));
        $data = Http::acceptJson()->connectTimeout(5)->timeout(15)
            ->post(config('vnpay.query_url'), $request)->throw()->json();
        //thêm log để debug
        Log::info('VNPAY QUERYDR RAW RESPONSE', [
            'payment_id' => $payment->id,
            'order_id' => $payment->order_id,
            'merchant_reference' => $payment->merchant_reference,

            'request' => [
                'vnp_RequestId' => $request['vnp_RequestId'] ?? null,
                'vnp_Version' => $request['vnp_Version'] ?? null,
                'vnp_Command' => $request['vnp_Command'] ?? null,
                'vnp_TmnCode' => $request['vnp_TmnCode'] ?? null,
                'vnp_TxnRef' => $request['vnp_TxnRef'] ?? null,
                'vnp_TransactionDate' => $request['vnp_TransactionDate'] ?? null,
                'vnp_CreateDate' => $request['vnp_CreateDate'] ?? null,
                'vnp_IpAddr' => $request['vnp_IpAddr'] ?? null,
                'vnp_OrderInfo' => $request['vnp_OrderInfo'] ?? null,
            ],

            'response' => $data,
        ]);

        // VNPAY trả về chữ ký sai, hoặc dữ liệu không hợp lệ, hoặc không phải giao dịch thanh toán.

        if (!is_array($data)) return null;
        $keys = [
            'vnp_ResponseId',
            'vnp_Command',
            'vnp_ResponseCode',
            'vnp_Message',
            'vnp_TmnCode',
            'vnp_TxnRef',
            'vnp_Amount',
            'vnp_BankCode',
            'vnp_PayDate',
            'vnp_TransactionNo',
            'vnp_TransactionType',
            'vnp_TransactionStatus',
            'vnp_OrderInfo',
            'vnp_PromotionCode',
            'vnp_PromotionAmount'
        ];
        foreach ($data as $value) if (!is_scalar($value) && $value !== null) return null;
        $hash = $this->mac(implode('|', array_map(fn($key) => (string) ($data[$key] ?? ''), $keys)));
        if (
            !hash_equals($hash, strtolower((string) ($data['vnp_SecureHash'] ?? '')))
            || ($data['vnp_TmnCode'] ?? '') !== config('vnpay.tmn_code')
            || ($data['vnp_TxnRef'] ?? '') !== $payment->merchant_reference
            || ($data['vnp_Command'] ?? '') !== 'querydr'
        ) return null;
        return array_map(fn($value) => (string) ($value ?? ''), $data);
    }

    public function expire(int $orderId): void
    {
        DB::transaction(function () use ($orderId) {
            $order = Order::lockForUpdate()->findOrFail($orderId);
            $deadline = OrderPaymentState::deadline($order);
            if (
                $order->payment_method !== 'VNPAY' || $order->order_status !== 'pending'
                || !$deadline || $deadline->isFuture() || $order->payment_review
            ) return;
            $payments = $order->payments()->orderBy('id')->lockForUpdate()->get();
            if (!OrderPaymentState::of($order, $payments)['can_cancel']) return;
            $this->stock->release($order);
            $order->update(['order_status' => 'cancelled']);
            $last = $payments->where('payment_method', 'VNPAY')->sortByDesc('id')->first();
            $last->forceFill(['status' => 'expired', 'failed_reason' => 'Hết thời hạn thanh toán của đơn.'])->save();
            $this->history($order, 'Hệ thống hủy đơn hết hạn sau khi xác định thanh toán không thành công.');
        }, 3);
    }
}
