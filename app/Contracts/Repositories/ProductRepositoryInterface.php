<?php

namespace App\Contracts\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;

interface ProductRepositoryInterface
{
    public function getAll(array $filters = [], int $perPage = 15): LengthAwarePaginator;
    public function findById(int $id): ?object;
    public function getProductWithRelations(int $id): ?object;
    public function create(array $data): object;
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;
    public function attachImages(int $productId, array $imagePaths, ?int $primaryIndex = null): void;
    public function syncVariantsAndPackages(int $productId, array $variantsData): void;
}
