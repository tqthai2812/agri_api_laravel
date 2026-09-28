<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OrderMoney
{
    // DECIMAL(12,2): orders.total_payment, product_packages.price.
    public const ORDER_MAX = 999999999999;

    // DECIMAL(18,2): giá trị dòng và giá vốn.
    public const VALUE_MAX = 999999999999999999;

    public static function cents(string|int $value): int
    {
        $value = (string) $value;

        if (!preg_match('/^[0-9]{1,16}(?:\.[0-9]{1,2})?$/D', $value)) {
            self::fail('Giá trị tiền không hợp lệ.');
        }

        [$whole, $fraction] = array_pad(
            explode('.', $value, 2),
            2,
            ''
        );

        $result = (int) $whole * 100
            + (int) str_pad($fraction, 2, '0');

        if ($result > self::VALUE_MAX) {
            self::fail('Giá trị tiền vượt giới hạn.');
        }

        return $result;
    }

    public static function decimal(int $cents): string
    {
        if ($cents < 0 || $cents > self::VALUE_MAX) {
            self::fail('Giá trị tiền vượt giới hạn.');
        }

        return intdiv($cents, 100)
            . '.'
            . str_pad(
                (string) ($cents % 100),
                2,
                '0',
                STR_PAD_LEFT
            );
    }

    public static function multiply(int $price, int $quantity): int
    {
        if (
            $price < 0
            || $quantity < 1
            || $price > intdiv(self::VALUE_MAX, $quantity)
        ) {
            self::fail('Số lượng hoặc thành tiền vượt giới hạn.');
        }

        return $price * $quantity;
    }

    public static function add(int $a, int $b): int
    {
        if (
            $a < 0
            || $b < 0
            || $a > self::VALUE_MAX - $b
        ) {
            self::fail('Tổng tiền vượt giới hạn.');
        }

        return $a + $b;
    }

    public static function assertOrderAmount(int $amount): void
    {
        if ($amount < 0 || $amount > self::ORDER_MAX) {
            self::fail(
                'Tổng tiền đơn hàng vượt giới hạn DECIMAL(12,2).'
            );
        }
    }

    public static function proportional(
        int $amount,
        int $part,
        int $total
    ): int {
        if ($total === 0) {
            return 0;
        }

        $row = DB::selectOne(
            'SELECT FLOOR(
                CAST(? AS DECIMAL(30,0))
                * CAST(? AS DECIMAL(30,0))
                / CAST(? AS DECIMAL(30,0))
            ) AS value',
            [$amount, $part, $total]
        );

        return (int) $row->value;
    }

    public static function lotCost(
        ?string $unitCost,
        int $quantity
    ): ?string {
        if ($unitCost === null) {
            return null;
        }

        if (
            $quantity < 1
            || !preg_match(
                '/^[0-9]{1,14}(?:\.[0-9]{1,4})?$/D',
                $unitCost
            )
        ) {
            self::fail(
                'Giá vốn lô hoặc số lượng xuất không hợp lệ.'
            );
        }

        $row = DB::selectOne(
            'SELECT ROUND(
                CAST(? AS DECIMAL(18,4))
                * CAST(? AS DECIMAL(10,0)),
                2
            ) AS value',
            [$unitCost, $quantity]
        );

        return self::decimal(
            self::cents((string) $row->value)
        );
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages([
            'amount' => [$message],
        ]);
    }
}
