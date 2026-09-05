<?php

namespace App\Http\Controllers\Admin\V1;

use App\Contracts\Services\NewsServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\News\StoreNewsRequest;
use App\Http\Requests\Admin\News\UpdateNewsRequest;
use App\Http\Resources\NewsResource;
use App\Models\News;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class NewsController extends Controller implements HasMiddleware
{
    public function __construct(
        protected NewsServiceInterface $newsService
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:article.view', only: ['index', 'show', 'statusCounts']),
            new Middleware('permission:article.create', only: ['store']),
            new Middleware('permission:article.update', only: ['update']),
            new Middleware('permission:article.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'search',
            'status',
            'is_draft',
            'is_published',
            'date_from',
            'date_to',
        ]);

        $perPage = (int) $request->input('per_page', 15);

        $news = $this->newsService->getAll($filters, $perPage);

        return NewsResource::collection($news)
            ->additional([
                'message' => 'Lấy danh sách bài viết thành công.',
            ])
            ->response();
    }

    public function store(StoreNewsRequest $request): JsonResponse
    {
        $news = $this->newsService->create(
            $request->validated(),
            auth()->id(),
            $request->file('title_image'),
            $request->file('images', [])
        );

        return response()->json([
            'message' => 'Tạo bài viết thành công.',
            'data' => new NewsResource($news),
        ], 201);
    }

    public function show(News $news): JsonResponse
    {
        $news = $this->newsService->getById($news->id);

        if (!$news) {
            return response()->json([
                'message' => 'Không tìm thấy bài viết.',
            ], 404);
        }

        return response()->json([
            'message' => 'Lấy chi tiết bài viết thành công.',
            'data' => new NewsResource($news),
        ]);
    }

    public function update(UpdateNewsRequest $request, News $news): JsonResponse
    {
        $news = $this->newsService->update(
            $news,
            $request->validated(),
            $request->file('title_image'),
            $request->file('images', [])
        );

        return response()->json([
            'message' => 'Cập nhật bài viết thành công.',
            'data' => new NewsResource($news),
        ]);
    }

    public function destroy(News $news): JsonResponse
    {
        $this->newsService->delete($news);

        return response()->json([
            'message' => 'Xóa bài viết thành công.',
        ]);
    }

    public function statusCounts(): JsonResponse
    {
        return response()->json([
            'message' => 'Lấy thống kê bài viết thành công.',
            'data' => $this->newsService->getStatusCounts(),
        ]);
    }
}
