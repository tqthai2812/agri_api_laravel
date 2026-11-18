<?php

namespace App\Services;

use App\Services\Ai\AiIndexCoordinator;
use Illuminate\Support\Facades\DB;

class FarmKnowledgeState
{
    public function __construct(private AiIndexCoordinator $index) {}
    public function guideIds($ids): void
    {
        if (!is_array($ids)) {
            $ids = $ids->pluck("product_usage_guides.id")->all();
        }
        foreach (array_unique($ids) as $id) {
            $this->index->touch("guide", (int) $id);
        }
    }
    public function crop(int $id): void
    {
        $this->guideIds(
            DB::table("product_usage_guides")
                ->where("crop_id", $id)
                ->pluck("id")
                ->all(),
        );
    }
    public function purpose(int $id): void
    {
        $this->guideIds(
            DB::table("product_usage_guides")
                ->where("purpose_id", $id)
                ->pluck("id")
                ->all(),
        );
    }
    public function product(int $id): void
    {
        $this->index->touch("product", $id);
        $this->guideIds(
            DB::table("product_usage_guides")
                ->join(
                    "product_variants",
                    "product_variants.id",
                    "=",
                    "product_usage_guides.variant_id",
                )
                ->where("product_variants.product_id", $id)
                ->pluck("product_usage_guides.id")
                ->all(),
        );
    }
}