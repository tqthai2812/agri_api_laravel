<?php

namespace App\Contracts\Services;

use App\Models\ProductReview;

interface AdminProductReviewServiceInterface
{
    public function index(array $filters, int $perPage): array;
    public function products(string $search): array;
    public function show(int $id): ProductReview;
    public function moderate(int $id, string $status, string $expectedStatus): ProductReview;
    public function reply(int $id, int $actorId, string $content): ProductReview;
    public function editReply(int $id, string $content, string $expectedContent, string $expectedStatus): ProductReview;
    public function moderateReply(int $id, string $status, string $expectedStatus): ProductReview;
}
