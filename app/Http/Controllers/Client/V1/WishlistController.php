<?php

namespace App\Http\Controllers\Client\V1;

use App\Contracts\Services\WishlistServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\Wishlist\DestroyWishlistItemsRequest;
use App\Http\Requests\Client\Wishlist\StoreWishlistItemRequest;
use App\Http\Resources\WishlistResource;
use App\Models\Wishlist;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class WishlistController extends Controller
{
    public function __construct(
        protected WishlistServiceInterface $wishlistService
    ) {}

    public function index(): JsonResponse
    {
        $items = $this->wishlistService->getUserWishlist(auth()->id());

        return WishlistResource::collection($items)
            ->additional([
                'message' => 'Lấy danh sách yêu thích thành công.',
            ])
            ->response();
    }

    public function store(StoreWishlistItemRequest $request): JsonResponse
    {
        try {
            $wishlist = $this->wishlistService->addItem(
                auth()->id(),
                (int) $request->validated('product_id')
            );

            return response()->json([
                'message' => 'Đã thêm sản phẩm vào danh sách yêu thích.',
                'data' => new WishlistResource($wishlist),
            ], 201);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function toggle(StoreWishlistItemRequest $request): JsonResponse
    {
        try {
            $result = $this->wishlistService->toggleItem(
                auth()->id(),
                (int) $request->validated('product_id')
            );

            return response()->json([
                'message' => $result['saved']
                    ? 'Đã thêm sản phẩm vào danh sách yêu thích.'
                    : 'Đã xóa sản phẩm khỏi danh sách yêu thích.',

                'data' => [
                    'saved' => $result['saved'],
                    'wishlist' => $result['wishlist']
                        ? new WishlistResource($result['wishlist'])
                        : null,
                ],
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function destroy(Wishlist $wishlist): JsonResponse
    {
        try {
            $this->wishlistService->removeItem(
                auth()->id(),
                $wishlist->id
            );

            return response()->json([
                'message' => 'Đã xóa sản phẩm khỏi danh sách yêu thích.',
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 404);
        }
    }

    public function destroyMany(DestroyWishlistItemsRequest $request): JsonResponse
    {
        try {
            $deleted = $this->wishlistService->removeItems(
                auth()->id(),
                $request->validated('ids')
            );

            return response()->json([
                'message' => $deleted > 1
                    ? 'Đã xóa các sản phẩm được chọn khỏi danh sách yêu thích.'
                    : 'Đã xóa sản phẩm khỏi danh sách yêu thích.',

                'data' => [
                    'deleted' => $deleted,
                ],
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
