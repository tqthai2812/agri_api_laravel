<?php

namespace App\Repositories;

use App\Contracts\Repositories\NewsRepositoryInterface;
use App\Models\News;
use App\Models\NewsImage;
use Illuminate\Pagination\LengthAwarePaginator;

class NewsRepository implements NewsRepositoryInterface
{
    public function getAll(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return News::query()
            ->with([
                'user:id,name,email',
                'images:id,news_id,image_url',
                'tags:id,tag_name',
            ])
            ->when(!empty($filters['search']), function ($query) use ($filters) {
                $search = $filters['search'];

                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('subtitle', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        })
                        ->orWhereHas('tags', function ($tagQuery) use ($search) {
                            $tagQuery->where('tag_name', 'like', "%{$search}%");
                        });
                });
            })
            ->when(isset($filters['is_published']) && $filters['is_published'] !== '', function ($query) use ($filters) {
                $query->where('is_published', filter_var($filters['is_published'], FILTER_VALIDATE_BOOLEAN));
            })
            ->when(isset($filters['is_draft']) && $filters['is_draft'] !== '', function ($query) use ($filters) {
                $query->where('is_draft', filter_var($filters['is_draft'], FILTER_VALIDATE_BOOLEAN));
            })
            ->when(!empty($filters['status']), function ($query) use ($filters) {
                match ($filters['status']) {
                    'published' => $query->where('is_published', true)->where('is_draft', false),
                    'draft' => $query->where('is_draft', true),
                    'hidden' => $query->where('is_published', false)->where('is_draft', false),
                    default => null,
                };
            })
            ->when(!empty($filters['date_from']), function ($query) use ($filters) {
                $query->whereDate('created_at', '>=', $filters['date_from']);
            })
            ->when(!empty($filters['date_to']), function ($query) use ($filters) {
                $query->whereDate('created_at', '<=', $filters['date_to']);
            })
            ->latest()
            ->paginate($perPage);
    }

    public function findById(int $id): ?News
    {
        return News::query()
            ->with([
                'user:id,name,email',
                'images:id,news_id,image_url',
                'tags:id,tag_name',
            ])
            ->find($id);
    }

    public function create(array $data): News
    {
        return News::create($data);
    }

    public function update(News $news, array $data): bool
    {
        return $news->update($data);
    }

    public function delete(News $news): bool
    {
        return $news->delete();
    }

    public function syncTags(News $news, array $tagIds): void
    {
        $news->tags()->sync($tagIds);
    }

    public function createImages(News $news, array $imagePaths): void
    {
        foreach ($imagePaths as $path) {
            NewsImage::create([
                'news_id' => $news->id,
                'image_url' => $path,
            ]);
        }
    }

    public function deleteImages(News $news, array $imageIds): array
    {
        $images = $news->images()
            ->whereIn('id', $imageIds)
            ->get();

        $paths = $images->pluck('image_url')->filter()->values()->all();

        $images->each->delete();

        return $paths;
    }

    public function getStatusCounts(): array
    {
        return [
            'all' => News::count(),
            'published' => News::where('is_published', true)->where('is_draft', false)->count(),
            'draft' => News::where('is_draft', true)->count(),
            'hidden' => News::where('is_published', false)->where('is_draft', false)->count(),
        ];
    }
}
