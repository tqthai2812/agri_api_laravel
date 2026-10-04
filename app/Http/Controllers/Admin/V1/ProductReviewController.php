<?php

namespace App\Http\Controllers\Admin\V1;

use App\Contracts\Services\AdminProductReviewServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Review\ReviewIndexRequest;
use App\Http\Requests\Admin\Review\SaveShopReplyRequest;
use App\Http\Requests\Admin\Review\UpdateReviewStatusRequest;
use App\Http\Resources\AdminProductReviewResource;
use App\Models\ProductReview;
use Illuminate\Http\JsonResponse;

class ProductReviewController extends Controller
{
    public function __construct(protected AdminProductReviewServiceInterface $service) {}

    public function index(ReviewIndexRequest $request): JsonResponse
    {
        $data = $request->validated();
        $result = $this->service->index($data, (int) ($data['per_page'] ?? 15));
        return AdminProductReviewResource::collection($result['reviews'])
            ->additional(['summary' => $result['summary']])->response();
    }

    public function products(ReviewIndexRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->service->products(trim($request->validated('search') ?? ''))]);
    }

    public function show(int $review): JsonResponse
    {
        return $this->result($this->service->show($review), 'Lấy chi tiết đánh giá thành công.');
    }

    public function status(UpdateReviewStatusRequest $request, int $review): JsonResponse
    {
        return $this->result($this->service->moderate(
            $review,
            $request->validated('status'),
            $request->validated('expected_status'),
        ), 'Đã cập nhật trạng thái đánh giá.');
    }

    public function reply(SaveShopReplyRequest $request, int $review): JsonResponse
    {
        return $this->result($this->service->reply(
            $review,
            (int) $request->user()->id,
            $request->validated('content'),
        ), 'Đã lưu phản hồi của cửa hàng.');
    }

    public function editReply(SaveShopReplyRequest $request, int $review): JsonResponse
    {
        return $this->result($this->service->editReply(
            $review,
            $request->validated('content'),
            $request->validated('expected_content'),
            $request->validated('expected_status'),
        ), 'Đã cập nhật nội dung phản hồi.');
    }

    public function replyStatus(UpdateReviewStatusRequest $request, int $review): JsonResponse
    {
        return $this->result($this->service->moderateReply(
            $review,
            $request->validated('status'),
            $request->validated('expected_status'),
        ), 'Đã cập nhật trạng thái phản hồi.');
    }

    private function result(ProductReview $review, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'data' => new AdminProductReviewResource($review)]);
    }
}
