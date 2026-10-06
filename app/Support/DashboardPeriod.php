<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class DashboardPeriod
{
    public const TIMEZONE = 'Asia/Ho_Chi_Minh';
    public readonly CarbonImmutable $start;
    public readonly CarbonImmutable $endExclusive;

    public function __construct(public readonly string $from, public readonly string $to)
    {
        $this->start = self::date($from);
        $last = self::date($to);
        if ($last->lt($this->start) || $this->start->diffInDays($last) > 365) {
            throw ValidationException::withMessages([
                'date_to' => ['Chọn khoảng ngày theo thứ tự, tối đa 366 ngày.'],
            ]);
        }
        $this->endExclusive = $last->addDay();
    }

    private static function date(string $value): CarbonImmutable
    {
        if (
            !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $m)
            || (int) $m[1] < 1970 || (int) $m[1] > 9998
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])
        ) {
            throw ValidationException::withMessages(['date_from' => ['Ngày không hợp lệ.']]);
        }
        return CarbonImmutable::createFromFormat('!Y-m-d', $value, self::TIMEZONE);
    }

    // Match the application's existing storage convention; do not change app.timezone.
    public function storageStart(): string
    {
        return $this->start->setTimezone(config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
    }

    public function storageEnd(): string
    {
        return $this->endExclusive->setTimezone(config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
    }

    public function days(): array
    {
        $days = [];
        for ($day = $this->start; $day->lt($this->endExclusive); $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }
        return $days;
    }
}
