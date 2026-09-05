<?php

namespace App\Services;

use App\Contracts\Repositories\NewsRepositoryInterface;
use App\Contracts\Services\NewsServiceInterface;
use App\Models\News;
use App\Models\Tag;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class NewsService implements NewsServiceInterface
{
    public function __construct(
        protected NewsRepositoryInterface $newsRepository
    ) {}

    public function getAll(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->newsRepository->getAll($filters, $perPage);
    }

    public function getById(int $id): ?News
    {
        return $this->newsRepository->findById($id);
    }

    public function create(array $data, int $userId, mixed $titleImage = null, array $imageFiles = []): News
    {
        return DB::transaction(function () use ($data, $userId, $titleImage, $imageFiles) {
            $tagNames = $data['tag_names'] ?? [];

            $payload = $this->preparePayload($data);
            $payload['user_id'] = $userId;
            $payload['slug'] = $this->makeUniqueSlug($payload['slug'] ?? $payload['title']);

            if ($titleImage) {
                $payload['title_image_url'] = $titleImage->store('news', 'public');
            }

            $news = $this->newsRepository->create($payload);

            $tagIds = $this->getTagIds($tagNames);
            $this->newsRepository->syncTags($news, $tagIds);

            $imagePaths = $this->storeImages($imageFiles);
            $this->newsRepository->createImages($news, $imagePaths);

            return $this->newsRepository->findById($news->id);
        });
    }

    public function update(News $news, array $data, mixed $titleImage = null, array $imageFiles = []): News
    {
        return DB::transaction(function () use ($news, $data, $titleImage, $imageFiles) {
            $tagNames = $data['tag_names'] ?? null;
            $deletedImageIds = $data['deleted_image_ids'] ?? [];

            $payload = $this->preparePayload($data, false);

            if (isset($payload['title']) || isset($payload['slug'])) {
                $slugSource = $payload['slug'] ?? $payload['title'] ?? $news->title;
                $payload['slug'] = $this->makeUniqueSlug($slugSource, $news->id);
            }

            if ($titleImage) {
                if ($news->title_image_url) {
                    Storage::disk('public')->delete($news->title_image_url);
                }

                $payload['title_image_url'] = $titleImage->store('news', 'public');
            }

            if (!empty($data['remove_title_image']) && $news->title_image_url) {
                Storage::disk('public')->delete($news->title_image_url);
                $payload['title_image_url'] = null;
            }

            $this->newsRepository->update($news, $payload);

            if (is_array($tagNames)) {
                $tagIds = $this->getTagIds($tagNames);
                $this->newsRepository->syncTags($news, $tagIds);
            }

            if (!empty($deletedImageIds)) {
                $deletedPaths = $this->newsRepository->deleteImages($news, $deletedImageIds);

                foreach ($deletedPaths as $path) {
                    Storage::disk('public')->delete($path);
                }
            }

            $imagePaths = $this->storeImages($imageFiles);
            $this->newsRepository->createImages($news, $imagePaths);

            return $this->newsRepository->findById($news->id);
        });
    }

    public function delete(News $news): bool
    {
        return DB::transaction(function () use ($news) {
            if ($news->title_image_url) {
                Storage::disk('public')->delete($news->title_image_url);
            }

            foreach ($news->images as $image) {
                if ($image->image_url) {
                    Storage::disk('public')->delete($image->image_url);
                }
            }

            $news->tags()->sync([]);

            return $this->newsRepository->delete($news);
        });
    }

    public function getStatusCounts(): array
    {
        return $this->newsRepository->getStatusCounts();
    }

    private function preparePayload(array $data, bool $creating = true): array
    {
        unset(
            $data['title_image'],
            $data['images'],
            $data['tag_names'],
            $data['tags'],
            $data['deleted_image_ids'],
            $data['remove_title_image']
        );

        if (array_key_exists('is_draft', $data)) {
            $data['is_draft'] = filter_var($data['is_draft'], FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists('is_published', $data)) {
            $data['is_published'] = filter_var($data['is_published'], FILTER_VALIDATE_BOOLEAN);
        }

        $isDraft = $data['is_draft'] ?? ($creating ? true : null);
        $isPublished = $data['is_published'] ?? ($creating ? false : null);

        if ($isDraft === true) {
            $data['is_published'] = false;
            $data['published_at'] = null;
        }

        if ($isDraft === false && $isPublished === true && empty($data['published_at'])) {
            $data['published_at'] = now();
        }

        return $data;
    }

    private function makeUniqueSlug(string $value, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($value);

        if (!$baseSlug) {
            $baseSlug = 'bai-viet';
        }

        $slug = $baseSlug;
        $counter = 1;

        while (
            News::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn($query) => $query->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function getTagIds(array $tagNames): array
    {
        return collect($tagNames)
            ->filter()
            ->map(fn($tagName) => trim((string) $tagName))
            ->filter()
            ->unique()
            ->map(function ($tagName) {
                return Tag::firstOrCreate([
                    'tag_name' => $tagName,
                ])->id;
            })
            ->values()
            ->all();
    }

    private function storeImages(array $imageFiles): array
    {
        return collect($imageFiles)
            ->filter()
            ->map(fn($image) => $image->store('news', 'public'))
            ->values()
            ->all();
    }
}
