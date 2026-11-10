<?php

namespace App\Services;

use App\Models\Crop;
use App\Models\Product;
use App\Models\ProductUsageGuide;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FarmUtilityService
{
    public function guideQuery(bool $public = false): Builder
    {
        $query = ProductUsageGuide::query()->with([
            'crop:id,name,slug,is_active',
            'variant:id,product_id,variant_name',
            'variant.product:id,product_name,is_show',
        ]);

        if ($public) {
            $query->where('is_active', true)
                ->whereHas('crop', fn (Builder $q) => $q->where('is_active', true))
                ->whereHas('variant.product', fn (Builder $q) => $q->where('is_show', true));
        }

        return $query;
    }

    public function filterGuides(Builder $query, array $filters): Builder
    {
        foreach (['variant_id', 'crop_id'] as $field) {
            if (!empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (!empty($filters['product_id'])) {
            $query->whereHas('variant', fn (Builder $q) => $q->where('product_id', $filters['product_id']));
        }
        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }
        if (($search = trim($filters['search'] ?? '')) !== '') {
            $query->where(function (Builder $q) use ($search): void {
                $term = '%'.$search.'%';
                $q->where('purpose', 'like', $term)
                    ->orWhere('growth_stage', 'like', $term)
                    ->orWhere('application_method', 'like', $term)
                    ->orWhereHas('crop', fn (Builder $c) => $c->where('name', 'like', $term))
                    ->orWhereHas('variant', fn (Builder $v) => $v->where('variant_name', 'like', $term))
                    ->orWhereHas('variant.product', fn (Builder $p) => $p->where('product_name', 'like', $term));
            });
        }

        return $query;
    }

    public function saveGuide(array $data, ?ProductUsageGuide $guide = null): ProductUsageGuide
    {
        return DB::transaction(function () use ($data, $guide): ProductUsageGuide {
            // Cùng khóa sản phẩm với luồng sửa/xóa sản phẩm hiện có.
            $variant = ProductVariant::query()->find($data['variant_id']);
            if (!$variant) {
                throw ValidationException::withMessages(['variant_id' => ['Biến thể không còn tồn tại.']]);
            }
            $product = Product::query()->lockForUpdate()->find($variant->product_id);
            $variant = ProductVariant::query()->where('product_id', $product?->id)
                ->lockForUpdate()->find($data['variant_id']);
            $crop = Crop::query()->lockForUpdate()->find($data['crop_id']);
            if (!$product || !$variant || !$crop) {
                throw ValidationException::withMessages(['guide' => ['Cây trồng hoặc biến thể đã thay đổi. Vui lòng tải lại.']]);
            }

            $guide = $guide ? ProductUsageGuide::query()->lockForUpdate()->findOrFail($guide->id) : new ProductUsageGuide;
            $guide->fill($data)->save();

            return $guide->load(['crop', 'variant.product']);
        }, 3);
    }
}