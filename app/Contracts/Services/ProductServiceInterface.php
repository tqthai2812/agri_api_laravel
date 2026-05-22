<?php

namespace App\Contracts\Services;

use Illuminate\Pagination\LengthAwarePaginator;

interface ProductServiceInterface
{
    public function listProducts(array $filters = [], int $perPage = 15): LengthAwarePaginator;
    public function getProductDetail(int $id): ?object;
    public function createProduct(array $data, array $imageFiles = []): object;
    public function updateProduct(int $id, array $data, array $imageFiles = []): bool;
    public function deleteProduct(int $id): bool;
}
