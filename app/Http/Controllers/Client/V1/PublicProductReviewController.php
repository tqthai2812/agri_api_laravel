<?php

namespace App\Http\Controllers\Client\V1;

use App\Contracts\Services\ProductReviewServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductReviewResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicProductReviewController extends Controller
{
    public function __construct(protected ProductReviewServiceInterface $reviews) {}

    public function index(Request $request, Product $product): JsonResponse
    {
        abort_unless($product->is_show, 404, 'Sản phẩm không tồn tại hoặc đã bị ẩn.');

        $data = $request->validate([
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,20'],
        ]);

        $result = $this->reviews->getPublished(
            (int) $product->id,
            isset($data['rating']) ? (int) $data['rating'] : null,
            (int) ($data['per_page'] ?? 10),
        );

        return ProductReviewResource::collection($result['reviews'])->additional([
            'message' => 'Lấy đánh giá sản phẩm thành công.',
            'summary' => $result['summary'],
        ])->response();
    }
}
