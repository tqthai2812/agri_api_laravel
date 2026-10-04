<?php

namespace App\Contracts\Services;

use App\Models\Order;

interface ProductReviewServiceInterface
{
    public function getOrderForUser(int $userId, int $orderId): Order;

    public function submitForOrder(int $userId, int $orderId, array $reviews): array;

    public function getPublished(int $productId, ?int $rating = null, int $perPage = 10): array;
}
