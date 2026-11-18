<?php

namespace App\Services\Ai;

class AiFault extends \RuntimeException
{
    public function __construct(
        public readonly string $status,
        string $message,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }

    public function payload(): array
    {
        return ["status" => $this->status, "message" => $this->getMessage()];
    }
}