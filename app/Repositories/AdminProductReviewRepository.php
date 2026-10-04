<?php

namespace App\Repositories;

use App\Contracts\Repositories\AdminProductReviewRepositoryInterface;
use App\Models\Product;
use App\Models\ProductReview;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class AdminProductReviewRepository implements AdminProductReviewRepositoryInterface
{
    private function query(array $filters): Builder
    {
        return ProductReview::query()->whereNull('parent_id')
            ->when(!empty($filters['product_id']), fn($q) => $q->where('product_id', $filters['product_id']))
            ->when(!empty($filters['rating']), fn($q) => $q->where('rating', $filters['rating']))
            ->when(!empty($filters['status']), fn($q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['search']), function ($query) use ($filters) {
                $search = trim($filters['search']);
                $query->where(fn($q) => $q->where('content', 'like', "%{$search}%")
                    ->orWhereHas('product', fn($p) => $p->where('product_name', 'like', "%{$search}%"))
                    ->orWhereHas('user', fn($u) => $u->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")));
            })
            ->when(($filters['reply_status'] ?? '') === 'answered', fn($q) => $q->whereHas(
                'replies',
                fn($r) => $r->withTrashed()->where('is_shop_reply', true),
            ))
            ->when(($filters['reply_status'] ?? '') === 'unanswered', fn($q) => $q->whereDoesntHave(
                'replies',
                fn($r) => $r->withTrashed()->where('is_shop_reply', true),
            ));
    }

    private function relations(): array
    {
        return [
            'user:id,name,email',
            'product:id,product_name',
            'orderItem:id,order_id,variant_name,size,unit',
            'orderItem.order:id,order_status',
            'replies' => fn($q) => $q->orderBy('id'),
            'replies.user:id,name',
        ];
    }

    private function details(Builder $query): Builder
    {
        return $query->with($this->relations())->withCount([
            'replies as shop_replies_count' => fn($q) => $q->withTrashed()->where('is_shop_reply', true),
        ]);
    }

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->details($this->query($filters))->orderByDesc('id')
            ->paginate($perPage)->withQueryString();
    }

    public function summary(array $filters): array
    {
        $query = $this->query($filters);
        $counts = (clone $query)->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')->pluck('total', 'status');

        return [
            'total' => (int) $counts->sum(),
            'published' => (int) ($counts['published'] ?? 0),
            'hidden' => (int) ($counts['hidden'] ?? 0),
            'pending' => (int) ($counts['pending'] ?? 0),
            'unanswered' => (clone $query)->whereDoesntHave('replies', fn($q) => $q
                ->withTrashed()->where('is_shop_reply', true))->count(),
        ];
    }

    public function products(string $search): array
    {
        return Product::query()->when($search !== '', fn($q) => $q
            ->where('product_name', 'like', '%' . $search . '%'))
            ->orderBy('product_name')->orderBy('id')->limit(20)
            ->get(['id', 'product_name'])->toArray();
    }

    public function find(int $id): ProductReview
    {
        return $this->details(ProductReview::query()->whereNull('parent_id'))->findOrFail($id);
    }

    public function lockRoot(int $id): ProductReview
    {
        $productId = ProductReview::query()->whereNull('parent_id')->whereKey($id)->value('product_id');
        abort_unless($productId, 404, 'Không tìm thấy đánh giá.');

        // Cùng thứ tự với luồng gửi đánh giá: khóa sản phẩm trước đánh giá.
        Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();

        return ProductReview::query()->whereNull('parent_id')->where('product_id', $productId)
            ->lockForUpdate()->findOrFail($id);
    }

    public function lockShopReply(int $rootId): ?ProductReview
    {
        return ProductReview::withTrashed()->where('parent_id', $rootId)
            ->where('is_shop_reply', true)->orderBy('id')->lockForUpdate()->first();
    }

    public function createReply(array $data): ProductReview
    {
        return ProductReview::create($data);
    }

    public function update(ProductReview $review, array $data): void
    {
        $review->update($data);
    }
}
