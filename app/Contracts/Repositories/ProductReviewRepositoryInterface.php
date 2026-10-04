<?php

namespace App\Contracts\Repositories;

use App\Models\Order;
use App\Models\ProductReview;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface ProductReviewRepositoryInterface
{
    public function lockOrderForUser(int $orderId, int $userId): Order;

    public function lockOrderItems(int $orderId): Collection;

    public function lockProducts(array $productIds): Collection;

    public function lockExistingReviews(array $itemIds): Collection;

    public function create(array $data): ProductReview;

    public function paginatePublished(int $productId, ?int $rating, int $perPage): LengthAwarePaginator;

    public function summary(int $productId): array;

    // Caller giữ khóa products và đang ở trong transaction.
    public function refreshRating(int $productId): void;
}
