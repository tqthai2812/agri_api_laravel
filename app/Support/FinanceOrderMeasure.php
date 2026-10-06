<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Validation\ValidationException;

final class FinanceOrderMeasure
{
    private static function money(mixed $v): ?int
    {
        if ($v === null) {
            return null;
        }
        try {
            $n = FinanceMoney::cents($v);
            return $n >= 0 ? $n : null;
        } catch (ValidationException) {
            return null;
        }
    }
    public static function of(Order $o): array
    {
        $total = self::money($o->total_payment);
        $ship = self::money($o->delivery_cost);
        $discount = self::money($o->discount_amount);
        $header =
            $total !== null &&
            $ship !== null &&
            $discount !== null &&
            $total >= $ship;
        $net = $header ? $total - $ship : null;
        $rowsValid = $o->items->isNotEmpty();
        $costComplete = $rowsValid;
        $rowsNet = $rowsDiscount = $qty = $cost = 0;
        foreach ($o->items as $i) {
            $price = self::money($i->price);
            $d = self::money($i->discount_amount);
            $n = self::money($i->net_sales_amount);
            $c = self::money($i->cost_total);
            $q = (int) $i->quantity;
            $gross = null;
            if ($price !== null && $q > 0) {
                try {
                    $gross = OrderMoney::multiply($price, $q);
                } catch (ValidationException) {
                }
            }
            if (
                $gross === null ||
                $d === null ||
                $n === null ||
                $d > $gross ||
                $n !== $gross - $d
            ) {
                $rowsValid = false;
            } else {
                $rowsNet = FinanceMoney::add($rowsNet, $n);
                $rowsDiscount = FinanceMoney::add($rowsDiscount, $d);
            }
            $qty += max(0, $q);
            if ($c === null) {
                $costComplete = false;
            } else {
                $cost = FinanceMoney::add($cost, $c);
            }
        }
        $consistent =
            $header &&
            $rowsValid &&
            $rowsNet === $net &&
            $rowsDiscount === $discount &&
            $qty === (int) $o->total_quantity;
        $issues = [];
        if (!$header) {
            $issues[] = "Tổng tiền đơn không hợp lệ";
        }
        if (!$consistent) {
            $issues[] = "Dữ liệu dòng hàng không khớp tổng đơn";
        }
        if (!$costComplete) {
            $issues[] = "Thiếu giá vốn đã chốt";
        }
        $paid = $o->payments->where("status", "paid");
        $validPaid =
            $header &&
            !$o->payment_review &&
            $paid->count() === 1 &&
            $paid->first()->payment_method === $o->payment_method &&
            self::money($paid->first()->amount) === $total;
        $paymentState = $validPaid
            ? "paid"
            : ($paid->isEmpty() &&
                !$o->payment_review &&
                $o->payment_method === "COD"
                ? "cod_uncollected"
                : "review");
        if ($paymentState === "review") {
            $issues[] = "Thanh toán cần đối chiếu";
        }
        $profit = $consistent && $costComplete ? $net - $cost : null;
        return [
            "id" => $o->id,
            "code" => "DH" . str_pad((string) $o->id, 6, "0", STR_PAD_LEFT),
            "completed_at" => $o->completed_at?->toIso8601String(),
            "payment_method" => $o->payment_method,
            "payment_state" => $paymentState,
            "net_sales" => $net === null ? null : FinanceMoney::decimal($net),
            "shipping_charged" => $header ? FinanceMoney::decimal($ship) : null,
            "discount" => $header ? FinanceMoney::decimal($discount) : null,
            "cost_total" => $costComplete ? FinanceMoney::decimal($cost) : null,
            "gross_profit" =>
            $profit === null ? null : FinanceMoney::decimal($profit),
            "profit_complete" => $profit !== null,
            "issues" => $issues,
        ];
    }
}
