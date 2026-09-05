<?php

namespace App\Http\Controllers\Client\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicNewsResource;
use App\Models\News;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicNewsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 6);

        $news = News::query()
            ->with([
                'user:id,name',
                'tags:id,tag_name',
            ])
            ->where('is_draft', false)
            ->where('is_published', true)
            ->where(function ($query) {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->input('search');

                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('subtitle', 'like', "%{$search}%")
                        ->orWhere('content', 'like', "%{$search}%")
                        ->orWhereHas('tags', function ($tagQuery) use ($search) {
                            $tagQuery->where('tag_name', 'like', "%{$search}%");
                        });
                });
            })
            ->when($request->filled('tag'), function ($query) use ($request) {
                $tag = $request->input('tag');

                $query->whereHas('tags', function ($tagQuery) use ($tag) {
                    $tagQuery->where('tag_name', $tag);
                });
            })
            ->latest('published_at')
            ->paginate($perPage);

        return PublicNewsResource::collection($news)
            ->additional([
                'message' => 'Lấy danh sách bài viết public thành công.',
            ])
            ->response();
    }

    public function show(string $slug): JsonResponse
    {
        $news = News::query()
            ->with([
                'user:id,name',
                'tags:id,tag_name',
                'images:id,news_id,image_url',
            ])
            ->where('slug', $slug)
            ->where('is_draft', false)
            ->where('is_published', true)
            ->where(function ($query) {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->first();

        if (!$news) {
            return response()->json([
                'message' => 'Không tìm thấy bài viết.',
            ], 404);
        }

        $news->increment('views');
        $news->refresh();

        return response()->json([
            'message' => 'Lấy chi tiết bài viết public thành công.',
            'data' => new PublicNewsResource($news),
        ]);
    }
}
