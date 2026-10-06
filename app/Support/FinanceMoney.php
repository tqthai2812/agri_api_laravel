<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class FinanceMoney
{
    public static function cents(mixed $value): int
    {
        if (
            (!is_string($value) && !is_int($value)) ||
            !preg_match('/^-?[0-9]{1,16}(?:\.[0-9]{1,2})?$/D', (string) $value)
        ) {
            self::fail();
        }
        $s = (string) $value;
        $negative = str_starts_with($s, "-");
        $n = OrderMoney::cents($negative ? substr($s, 1) : $s);
        return $negative ? -$n : $n;
    }
    public static function decimal(int $n): string
    {
        return ($n < 0 ? "-" : "") . OrderMoney::decimal(abs($n));
    }
    public static function add(int $a, int $b): int
    {
        // Each operand is bounded by VALUE_MAX; adding two cannot overflow a 64-bit integer.
        $sum = $a + $b;
        if (abs($sum) > OrderMoney::VALUE_MAX) {
            self::fail();
        }
        return $sum;
    }
    public static function fail(): never
    {
        throw ValidationException::withMessages([
            "amount" => [
                "Giá trị hoặc tổng tiền vượt giới hạn DECIMAL(18,2). Hãy thu hẹp kỳ báo cáo nếu cần.",
            ],
        ]);
    }
}
