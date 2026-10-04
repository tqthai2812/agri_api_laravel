<?php

namespace App\Console\Commands;

use App\Contracts\Repositories\ProductReviewRepositoryInterface;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecountProductReviews extends Command
{
    protected $signature = 'reviews:recount';

    protected $description = 'Tính lại số lượng và điểm trung bình từ đánh giá gốc đang công khai';

    public function handle(ProductReviewRepositoryInterface $reviews): int
    {
        $count = 0;

        Product::query()->select('id')->chunkById(100, function ($products) use ($reviews, &$count) {
            foreach ($products as $product) {
                DB::transaction(function () use ($reviews, $product) {
                    if ($reviews->lockProducts([$product->id])->has($product->id)) {
                        $reviews->refreshRating((int) $product->id);
                    }
                }, 3);
                $count++;
            }
        });

        $this->info("Đã tính lại thống kê đánh giá của $count sản phẩm.");

        return self::SUCCESS;
    }
}