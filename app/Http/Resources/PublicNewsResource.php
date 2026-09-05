<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class PublicNewsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'content' => $this->content,
            'excerpt' => Str::limit(strip_tags((string) $this->content), 150),

            'slug' => $this->slug,
            'title_image_url' => $this->imageUrl($this->title_image_url),

            'views' => (int) $this->views,

            'author' => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ] : null,

            'tags' => $this->tags?->map(fn($tag) => [
                'id' => $tag->id,
                'tag_name' => $tag->tag_name,
            ])->values() ?? [],

            'published_at' => $this->published_at?->toDateTimeString(),
            'published_date' => $this->published_at?->format('d/m/Y'),

            'created_at' => $this->created_at?->toDateTimeString(),
        ];
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
