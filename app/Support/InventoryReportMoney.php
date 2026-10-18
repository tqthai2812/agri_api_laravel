<?php

namespace App\Support;

final class InventoryReportMoney
{
    // unit_cost is DECIMAL(18,4). Split the multiplication before converting to cents.
    public static function value(mixed $cost, int $quantity): ?string
    {
        if ($quantity === 0) {
            return "0.00";
        }
        if (
            $quantity < 0 ||
            $cost === null ||
            !preg_match(
                '/^([0-9]{1,14})(?:\.([0-9]{1,4}))?$/D',
                (string) $cost,
                $m,
            )
        ) {
            return null;
        }
        $scaled = (int) ($m[1] . str_pad($m[2] ?? "", 4, "0"));
        $cents = intdiv($scaled, 100);
        $fraction = $scaled % 100;
        if ($cents > intdiv(OrderMoney::VALUE_MAX, $quantity)) {
            return null;
        }
        $result = $cents * $quantity + intdiv($fraction * $quantity + 50, 100);
        return $result > OrderMoney::VALUE_MAX
            ? null
            : OrderMoney::decimal($result);
    }
}
