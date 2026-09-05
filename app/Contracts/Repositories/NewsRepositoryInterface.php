<?php

namespace App\Contracts\Repositories;

use App\Models\News;
use Illuminate\Pagination\LengthAwarePaginator;

interface NewsRepositoryInterface
{
    public function getAll(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function findById(int $id): ?News;

    public function create(array $data): News;

    public function update(News $news, array $data): bool;

    public function delete(News $news): bool;

    public function syncTags(News $news, array $tagIds): void;

    public function createImages(News $news, array $imagePaths): void;

    public function deleteImages(News $news, array $imageIds): array;

    public function getStatusCounts(): array;
}
