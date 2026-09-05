<?php

namespace App\Contracts\Services;

use App\Models\News;
use Illuminate\Pagination\LengthAwarePaginator;

interface NewsServiceInterface
{
    public function getAll(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function getById(int $id): ?News;

    public function create(array $data, int $userId, mixed $titleImage = null, array $imageFiles = []): News;

    public function update(News $news, array $data, mixed $titleImage = null, array $imageFiles = []): News;

    public function delete(News $news): bool;

    public function getStatusCounts(): array;
}
