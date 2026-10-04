<?php

namespace App\Contracts\Repositories;

use App\Models\ProductReview;
use Illuminate\Pagination\LengthAwarePaginator;

interface AdminProductReviewRepositoryInterface
{
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;
    public function summary(array $filters): array;
    public function products(string $search): array;
    public function find(int $id): ProductReview;
    public function lockRoot(int $id): ProductReview;
    public function lockShopReply(int $rootId): ?ProductReview;
    public function createReply(array $data): ProductReview;
    public function update(ProductReview $review, array $data): void;
}
