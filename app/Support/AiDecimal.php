<?php

namespace App\Support;

use App\Services\Ai\AiFault;

/** Số không âm dạng chuỗi. Không dùng float cho liều lượng, làm tròn số chai hoặc tiền. */
final class AiDecimal
{
    public static function n(string $n): string
    {
        return ltrim($n, "0") ?: "0";
    }
    public static function cmp(string $a, string $b): int
    {
        $a = self::n($a);
        $b = self::n($b);
        return strlen($a) <=> strlen($b) ?: strcmp($a, $b);
    }
    public static function add(string $a, string $b): string
    {
        $out = "";
        $carry = 0;
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;
        while ($i >= 0 || $j >= 0 || $carry) {
            $n =
                ($i >= 0 ? (int) $a[$i--] : 0) +
                ($j >= 0 ? (int) $b[$j--] : 0) +
                $carry;
            $out = $n % 10 . $out;
            $carry = intdiv($n, 10);
        }
        return self::n($out);
    }
    public static function sub(string $a, string $b): string
    {
        if (self::cmp($a, $b) < 0) {
            throw new \LogicException("Negative unsigned decimal");
        }
        $out = "";
        $borrow = 0;
        $j = strlen($b) - 1;
        for ($i = strlen($a) - 1; $i >= 0; $i--) {
            $n = (int) $a[$i] - ($j >= 0 ? (int) $b[$j--] : 0) - $borrow;
            $borrow = $n < 0 ? 1 : 0;
            $out = ($n < 0 ? $n + 10 : $n) . $out;
        }
        return self::n($out);
    }
    public static function mul(string $a, string $b): string
    {
        $out = "0";
        $z = "";
        for ($i = strlen($b) - 1; $i >= 0; $i--) {
            $part = "0";
            for ($k = 0; $k < (int) $b[$i]; $k++) {
                $part = self::add($part, $a);
            }
            $out = self::add($out, $part . $z);
            $z .= "0";
        }
        return self::n($out);
    }
    public static function div(string $a, string $b): array
    {
        if (self::n($b) === "0") {
            throw new \LogicException("Division by zero");
        }
        $q = "";
        $r = "0";
        foreach (str_split($a) as $c) {
            $r = self::n($r . $c);
            $digit = 0;
            while (self::cmp($r, $b) >= 0) {
                $r = self::sub($r, $b);
                $digit++;
            }
            $q .= $digit;
        }
        return [self::n($q), $r];
    }
    public static function scaled(mixed $value, bool $positive = true): string
    {
        $s = (string) $value;
        if (
            !preg_match('/^(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,6}))?$/D', $s, $m)
        ) {
            throw new AiFault(
                "invalid_input",
                "Nhập số thập phân hợp lệ, tối đa 6 chữ số sau dấu chấm.",
            );
        }
        $n = self::n($m[1] . str_pad($m[2] ?? "", 6, "0"));
        if ($positive && $n === "0") {
            throw new AiFault("invalid_input", "Giá trị phải lớn hơn 0.");
        }
        return $n;
    }
    public static function fixed(string $n, int $places = 6): string
    {
        $n = str_pad($n, $places + 1, "0", STR_PAD_LEFT);
        if ($places === 0) {
            return self::n($n);
        }
        return substr($n, 0, -$places) . "." . substr($n, -$places);
    }
    public static function render(
        array $r,
        bool $up = false,
        int $places = 6,
    ): string {
        [$n, $rem] = self::div(
            self::mul($r[0], "1" . str_repeat("0", $places)),
            $r[1],
        );
        if ($up && $rem !== "0") {
            $n = self::add($n, "1");
        }
        return self::fixed($n, $places);
    }
    public static function compare(array $a, array $b): int
    {
        return self::cmp(self::mul($a[0], $b[1]), self::mul($b[0], $a[1]));
    }
    public static function ceilPackages(array $amount, string $size): string
    {
        [$n, $r] = self::div(
            self::mul($amount[0], "1000000"),
            self::mul($amount[1], $size),
        );
        return $r === "0" ? $n : self::add($n, "1");
    }
}