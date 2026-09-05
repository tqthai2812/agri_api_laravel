<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class NewsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'user_id' => $this->user_id,
            'user' => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ] : null,

            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'content' => $this->content,
            'excerpt' => Str::limit(strip_tags((string) $this->content), 150),

            'slug' => $this->slug,

            'title_image_url' => $this->imageUrl($this->title_image_url),

            'is_draft' => (bool) $this->is_draft,
            'is_published' => (bool) $this->is_published,
            'published_at' => $this->published_at?->toDateTimeString(),

            'status' => $this->getStatus(),
            'status_label' => $this->getStatusLabel(),

            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,

            'views' => (int) $this->views,

            'images' => $this->images?->map(fn($image) => [
                'id' => $image->id,
                'image_url' => $this->imageUrl($image->image_url),
            ])->values() ?? [],

            'tags' => $this->tags?->map(fn($tag) => [
                'id' => $tag->id,
                'tag_name' => $tag->tag_name,
            ])->values() ?? [],

            'tag_names' => $this->tags?->pluck('tag_name')->values() ?? [],

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }

    private function getStatus(): string
    {
        if ($this->is_draft) {
            return 'draft';
        }

        if ($this->is_published) {
            return 'published';
        }

        return 'hidden';
    }

    private function getStatusLabel(): string
    {
        return match ($this->getStatus()) {
            'published' => 'Đã xuất bản',
            'draft' => 'Bản nháp',
            'hidden' => 'Đã ẩn',
            default => 'Không xác định',
        };
    }

    private function imageUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        return asset('storage/' . ltrim($path, '/'));
    }
}
