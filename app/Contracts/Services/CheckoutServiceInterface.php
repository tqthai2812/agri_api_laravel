<?php

namespace App\Contracts\Services;

use App\Models\User;

interface CheckoutServiceInterface
{
    public function options(User $user): array;

    public function preview(User $user, array $data): array;

    public function checkout(User $user, array $data): array;
}
