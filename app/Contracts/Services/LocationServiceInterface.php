<?php

namespace App\Contracts\Services;

interface LocationServiceInterface
{
    public function provinces(): array;

    public function wards(string $provinceId): array;

    public function resolve(string $provinceId, string $wardId): array;

    public function shippingRegions(): array;
}
