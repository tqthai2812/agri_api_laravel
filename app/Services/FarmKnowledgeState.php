<?php

namespace App\Services;

use App\Models\AiKnowledgeDocument;
use App\Models\ProductUsageGuide;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FarmKnowledgeState
{
    public function guideIds($ids): void
    {
        // Chỉ vô hiệu chỉ mục cũ. Job embedding/Qdrant sẽ được triển khai sau.
        $this->invalidate(AiKnowledgeDocument::query()->whereIn('usage_guide_id', $ids));
    }

    public function crop(int $id): void
    {
        $this->guideIds(ProductUsageGuide::query()->where('crop_id', $id)->select('id'));
    }

    public function purpose(int $id): void
    {
        $this->guideIds(ProductUsageGuide::query()->where('purpose_id', $id)->select('id'));
    }

    public function product(int $id): void
    {
        $this->invalidate(AiKnowledgeDocument::query()->where('product_id', $id));
        $this->guideIds(ProductUsageGuide::query()->whereHas('variant', fn ($q) => $q->where('product_id', $id))->select('id'));
    }

    private function invalidate(Builder $query): void
    {
        DB::transaction(function () use ($query): void {
            foreach ($query->orderBy('id')->lockForUpdate()->get() as $document) {
                // Đổi token để job cũ không thể công bố lại nội dung sau khi nguồn đổi.
                $document->update(['status' => 'pending', 'indexed_at' => null, 'last_error' => null, 'vector_point_id' => (string) Str::uuid()]);
            }
        }, 3);
    }
}