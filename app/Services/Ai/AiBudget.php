<?php

namespace App\Services\Ai;

class AiBudget
{
    private ?float $deadline = null;

    public function begin(): void
    {
        $this->deadline =
            microtime(true) +
            min(90, max(5, (int) config("ai.request_timeout", 50)));
    }

    public function timeout(int $ceiling = 12): int
    {
        $remaining =
            $this->deadline === null
                ? $ceiling
                : (int) floor($this->deadline - microtime(true));
        if ($remaining < 1) {
            throw new AiFault(
                "service_unavailable",
                "Tư vấn đang mất nhiều thời gian. Bạn thử gửi lại nhé.",
                503,
            );
        }
        return max(1, min($ceiling, $remaining));
    }
}