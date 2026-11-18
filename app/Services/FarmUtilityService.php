<?php

namespace App\Services;

use App\Models\{
    Crop,
    CropGrowthStage,
    Product,
    ProductPurpose,
    ProductUsageGuide,
    ProductVariant,
};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FarmUtilityService
{
    public function guideQuery(bool $public = false): Builder
    {
        $query = ProductUsageGuide::query()->with([
            "crop:id,name,slug,aliases,is_active",
            "purpose:id,name,slug,aliases,is_active",
            "growthStages" => function ($q) use ($public): void {
                if ($public) {
                    $q->where("crop_growth_stages.is_active", true);
                }
            },
            "variant:id,product_id,variant_name",
            "variant.product:id,product_name,is_show",
        ]);
        if ($public) {
            $query
                ->where("is_active", true)
                ->whereNotNull("label_source")
                ->whereRaw("CHAR_LENGTH(TRIM(label_source)) > 0")
                ->whereRaw("CHAR_LENGTH(TRIM(instructions)) > 0")
                ->whereHas("crop", fn($q) => $q->where("is_active", true))
                ->whereHas("purpose", fn($q) => $q->where("is_active", true))
                ->whereHas(
                    "variant.product",
                    fn($q) => $q->where("is_show", true),
                )
                ->where(function ($q): void {
                    $q->whereIn("growth_stage_scope", [
                        "all",
                        "unspecified",
                    ])->orWhere(
                        fn($specific) => $specific
                            ->where("growth_stage_scope", "specific")
                            ->whereHas(
                                "growthStages",
                                fn($stage) => $stage->where(
                                    "crop_growth_stages.is_active",
                                    true,
                                ),
                            ),
                    );
                });
        }

        return $query;
    }

    public function filterGuides(Builder $query, array $filters): Builder
    {
        foreach (["variant_id", "crop_id", "purpose_id"] as $field) {
            if (!empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (!empty($filters["product_id"])) {
            $query->whereHas(
                "variant",
                fn($q) => $q->where("product_id", $filters["product_id"]),
            );
        }
        if (isset($filters["is_active"])) {
            $query->where("is_active", $filters["is_active"]);
        }
        if (!empty($filters["growth_stage_scope"])) {
            $query->where("growth_stage_scope", $filters["growth_stage_scope"]);
        }
        if (!empty($filters["growth_stage_id"])) {
            $stage = CropGrowthStage::query()
                ->where("is_active", true)
                ->find($filters["growth_stage_id"]);
            if (!$stage) {
                return $query->whereRaw("1 = 0");
            }
            $query
                ->where("crop_id", $stage->crop_id)
                ->where(function ($q) use ($stage): void {
                    $q->where("growth_stage_scope", "all")->orWhere(
                        fn($specific) => $specific
                            ->where("growth_stage_scope", "specific")
                            ->whereHas(
                                "growthStages",
                                fn($stages) => $stages->where(
                                    "crop_growth_stages.id",
                                    $stage->id,
                                ),
                            ),
                    );
                });
        }
        if (($search = trim($filters["search"] ?? "")) !== "") {
            $query->where(function ($q) use ($search): void {
                $term = "%" . $search . "%";
                $q->where("application_method", "like", $term)
                    ->orWhereHas(
                        "purpose",
                        fn($p) => $p
                            ->where("name", "like", $term)
                            ->orWhereJsonContains("aliases", $search),
                    )
                    ->orWhereHas(
                        "growthStages",
                        fn($s) => $s
                            ->where("name", "like", $term)
                            ->orWhereJsonContains("aliases", $search),
                    )
                    ->orWhereHas(
                        "crop",
                        fn($c) => $c
                            ->where("name", "like", $term)
                            ->orWhereJsonContains("aliases", $search),
                    )
                    ->orWhereHas(
                        "variant",
                        fn($v) => $v->where("variant_name", "like", $term),
                    )
                    ->orWhereHas(
                        "variant.product",
                        fn($p) => $p->where("product_name", "like", $term),
                    );
            });
        }

        return $query;
    }

    public function saveGuide(
        array $data,
        ?ProductUsageGuide $guide = null,
    ): ProductUsageGuide {
        return DB::transaction(function () use (
            $data,
            $guide,
        ): ProductUsageGuide {
            $variant = ProductVariant::query()->find($data["variant_id"]);
            $product = $variant
                ? Product::query()->lockForUpdate()->find($variant->product_id)
                : null;
            $variant = ProductVariant::query()
                ->where("product_id", $product?->id)
                ->lockForUpdate()
                ->find($data["variant_id"]);
            $crop = Crop::query()->lockForUpdate()->find($data["crop_id"]);
            $purpose = ProductPurpose::query()
                ->lockForUpdate()
                ->find($data["purpose_id"]);
            if (!$product || !$variant || !$crop || !$purpose) {
                throw ValidationException::withMessages([
                    "guide" => [
                        "Biến thể, cây trồng hoặc công dụng đã thay đổi. Vui lòng tải lại.",
                    ],
                ]);
            }
            $scope = $data["growth_stage_scope"] ?? "unspecified";
            $ids = array_map("intval", $data["growth_stage_ids"] ?? []);
            if (
                !in_array($scope, ["unspecified", "specific", "all"], true) ||
                count($ids) !== count(array_unique($ids)) ||
                ($scope === "specific" ? count($ids) === 0 : count($ids) > 0)
            ) {
                throw ValidationException::withMessages([
                    "growth_stage_ids" => [
                        "Chọn ít nhất một giai đoạn cho phạm vi cụ thể; phạm vi khác phải để trống danh sách.",
                    ],
                ]);
            }
            $stages = CropGrowthStage::query()
                ->where("crop_id", $crop->id)
                ->whereIn("id", $ids)
                ->orderBy("id")
                ->lockForUpdate()
                ->get();
            if ($stages->count() !== count($ids)) {
                throw ValidationException::withMessages([
                    "growth_stage_ids" => [
                        "Có giai đoạn không thuộc cây trồng đã chọn hoặc đã bị xóa.",
                    ],
                ]);
            }
            if (
                (bool) $data["is_active"] &&
                (!$crop->is_active ||
                    !$purpose->is_active ||
                    $stages->contains(fn($stage) => !$stage->is_active))
            ) {
                throw ValidationException::withMessages([
                    "is_active" => [
                        "Muốn bật hướng dẫn, cây trồng, công dụng và các giai đoạn đã chọn phải đang bật.",
                    ],
                ]);
            }
            if (
                (bool) $data["is_active"] &&
                (trim((string) ($data["label_source"] ?? "")) === "" ||
                    trim((string) ($data["instructions"] ?? "")) === "")
            ) {
                throw ValidationException::withMessages([
                    "label_source" => [
                        "Hướng dẫn đang bật phải có nội dung và nguồn nhãn/tài liệu.",
                    ],
                ]);
            }
            $guide = $guide
                ? ProductUsageGuide::query()
                    ->lockForUpdate()
                    ->findOrFail($guide->id)
                : new ProductUsageGuide();
            if ($guide->exists) {
                $guide->growthStages()->detach();
            }
            unset($data["growth_stage_ids"]);
            $guide->fill($data)->save();
            $guide
                ->growthStages()
                ->sync(array_fill_keys($ids, ["crop_id" => $crop->id]));
            app(FarmKnowledgeState::class)->guideIds([$guide->id]);

            return $this->guideQuery()->findOrFail($guide->id);
        }, 3);
    }

    public function deleteGuide(ProductUsageGuide $guide): void
    {
        DB::transaction(function () use ($guide): void {
            $guide = ProductUsageGuide::query()
                ->lockForUpdate()
                ->findOrFail($guide->id);
            app(\App\Services\Ai\AiIndexCoordinator::class)->forget(
                "guide",
                (int) $guide->id,
            );
            $guide->delete(); // FK ghép tự xóa các liên kết giai đoạn.
        }, 3);
    }
}