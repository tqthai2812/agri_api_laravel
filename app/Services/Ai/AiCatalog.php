<?php

namespace App\Services\Ai;

use App\Services\FarmUtilityService;
use App\Support\ProductStockQuery;
use Illuminate\Support\Facades\DB;

class AiCatalog
{
    public function __construct(private FarmUtilityService $farm) {}

    public function options(
        string $kind,
        string $search = "",
        ?int $cropId = null,
    ): array {
        $tables = [
            "crop" => "crops",
            "purpose" => "product_purposes",
            "growth_stage" => "crop_growth_stages",
            "category" => "categories",
        ];
        if (!isset($tables[$kind])) {
            throw new AiFault("invalid_input", "Loại lựa chọn chưa hợp lệ.");
        }
        if ($kind === "growth_stage" && !$cropId) {
            throw new AiFault(
                "needs_clarification",
                "Bạn muốn xem giai đoạn của cây nào?",
            );
        }
        $table = $tables[$kind];
        $name = $kind === "category" ? "category_name" : "name";
        $q = DB::table($table);
        if ($kind !== "category") {
            $q->where("is_active", true);
        }
        if ($kind === "growth_stage") {
            $q->where("crop_id", $cropId);
        }
        if (trim($search) !== "") {
            $q->where(function ($q) use ($search, $kind, $name) {
                $q->where($name, "like", "%" . trim($search) . "%");
                if ($kind !== "category") {
                    $q->orWhereJsonContains("aliases", trim($search));
                }
            });
        }
        // Tất cả kết quả alias mơ hồ đều được trả; model phải hỏi khách xác nhận.
        return $q
            ->orderBy("id")
            ->limit(40)
            ->get()
            ->map(
                fn($r) => [
                    "id" => (int) $r->id,
                    "name" => $r->$name,
                    "kind" => $kind,
                    "crop_id" => $r->crop_id ?? null,
                ],
            )
            ->all();
    }

    public function mergeContext(array $current, array $patch): array
    {
        $allowed = [
            "crop_id",
            "purpose_id",
            "growth_stage_id",
            "category_id",
            "unit_type",
            "max_price",
            "budget_scope",
            "guide_id",
        ];
        if (array_diff(array_keys($patch), $allowed)) {
            throw new AiFault(
                "invalid_input",
                "Điều kiện tư vấn không hợp lệ.",
            );
        }
        foreach (["crop_id", "purpose_id", "growth_stage_id"] as $k) {
            if (
                array_key_exists($k, $patch) &&
                ($patch[$k] ?? null) !== ($current[$k] ?? null)
            ) {
                unset($current["guide_id"]);
                break;
            }
        }
        if (
            array_key_exists("crop_id", $patch) &&
            ($patch["crop_id"] ?? null) !== ($current["crop_id"] ?? null)
        ) {
            unset($current["growth_stage_id"]);
        }
        foreach ($patch as $key => $value) {
            if ($value === null || $value === "") {
                unset($current[$key]);
                continue;
            }
            if (
                in_array(
                    $key,
                    [
                        "crop_id",
                        "purpose_id",
                        "growth_stage_id",
                        "category_id",
                        "guide_id",
                    ],
                    true,
                )
            ) {
                if (!is_int($value) || $value < 1) {
                    throw new AiFault(
                        "invalid_input",
                        "Mã danh mục không hợp lệ.",
                    );
                }
                $kind = [
                    "crop_id" => "crop",
                    "purpose_id" => "purpose",
                    "growth_stage_id" => "growth_stage",
                    "category_id" => "category",
                    "guide_id" => "guide",
                ][$key];
                $table = [
                    "crop" => "crops",
                    "purpose" => "product_purposes",
                    "growth_stage" => "crop_growth_stages",
                    "category" => "categories",
                    "guide" => "product_usage_guides",
                ][$kind];
                $q = DB::table($table)->where("id", $value);
                if ($kind !== "category") {
                    $q->where("is_active", true);
                }
                if ($kind === "growth_stage") {
                    $q->where(
                        "crop_id",
                        $patch["crop_id"] ?? ($current["crop_id"] ?? 0),
                    );
                }
                if (!$q->exists()) {
                    throw new AiFault(
                        "no_catalog_match",
                        "Lựa chọn đã thay đổi hoặc không thuộc cây đang chọn.",
                    );
                }
            } elseif (
                $key === "unit_type" &&
                !in_array($value, ["liquid", "solid", "any"], true)
            ) {
                throw new AiFault("invalid_input", "Nhóm đơn vị không hợp lệ.");
            } elseif (
                $key === "budget_scope" &&
                !in_array($value, ["per_package", "total", "unclear"], true)
            ) {
                throw new AiFault(
                    "invalid_input",
                    "Phạm vi ngân sách không hợp lệ.",
                );
            } elseif ($key === "max_price") {
                \App\Support\AiDecimal::scaled($value);
            }
            $current[$key] = $value;
        }
        if (
            isset($current["max_price"]) &&
            ($current["budget_scope"] ?? "unclear") !== "per_package"
        ) {
            // Không tự hiểu "200 nghìn" thành giá mỗi chai.
            $current["budget_scope"] = $current["budget_scope"] ?? "unclear";
        }
        return $current;
    }

    public function guides(array $context = [])
    {
        $q = $this->farm->filterGuides($this->farm->guideQuery(true), $context);
        // Nếu khách chưa xác nhận giai đoạn, chỉ có phạm vi nguồn xác nhận "all" là đủ cơ sở đề nghị sử dụng.
        if (
            !empty($context["advisory"]) &&
            empty($context["growth_stage_id"])
        ) {
            $q->where("growth_stage_scope", "all");
        }
        return $q;
    }

    public function guide(int $id, array $context = []): ?array
    {
        $g = $this->guides($context)->find($id);
        if (
            !$g ||
            !$g->crop ||
            !$g->purpose ||
            !$g->variant ||
            !$g->variant->product
        ) {
            return null;
        }
        return [
            "id" => (int) $g->id,
            "variant_id" => (int) $g->variant_id,
            "product_id" => (int) $g->variant->product_id,
            "crop_id" => (int) $g->crop_id,
            "crop" => $g->crop->name,
            "purpose_id" => (int) $g->purpose_id,
            "purpose" => $g->purpose->name,
            "growth_stage_scope" => $g->growth_stage_scope,
            "growth_stages" => $g->growthStages
                ->map(fn($s) => ["id" => $s->id, "name" => $s->name])
                ->all(),
            "application_method" => $g->application_method,
            "dose_unit" => $g->dose_unit,
            "dose_per_ha_min" => $g->dose_per_ha_min,
            "dose_per_ha_max" => $g->dose_per_ha_max,
            "dose_per_liter_min" => $g->dose_per_liter_min,
            "dose_per_liter_max" => $g->dose_per_liter_max,
            "water_l_per_ha_min" => $g->water_l_per_ha_min,
            "water_l_per_ha_max" => $g->water_l_per_ha_max,
            "pre_harvest_days" => $g->pre_harvest_days,
            "instructions" => $this->text($g->instructions, 65535),
            "safety_warning" => $this->text($g->safety_warning, 65535),
            "label_source" => $g->label_source,
        ];
    }

    public function packages(array $context = [])
    {
        $q = ProductStockQuery::packages()
            ->join(
                "product_variants as av",
                "av.id",
                "=",
                "product_packages.variant_id",
            )
            ->join("products as ap", "ap.id", "=", "av.product_id")
            ->where("ap.is_show", true)
            ->addSelect(
                "ap.id as product_id",
                "ap.product_name",
                "ap.brand",
                "ap.description",
                "ap.usage_instructions",
                "ap.safety_warning",
                "av.variant_name",
            );
        if (!empty($context["category_id"])) {
            $q->where("ap.category_id", $context["category_id"]);
        }
        if (($context["unit_type"] ?? "any") === "liquid") {
            $q->whereIn("product_packages.unit", ["ml", "l"]);
        }
        if (($context["unit_type"] ?? "any") === "solid") {
            $q->whereIn("product_packages.unit", ["g", "kg"]);
        }
        if (
            isset($context["max_price"]) &&
            ($context["budget_scope"] ?? "") === "per_package"
        ) {
            $q->where("product_packages.price", "<=", $context["max_price"]);
        }
        if (!empty($context["advisory"])) {
            $guides = $this->guides($context)->select(
                "product_usage_guides.variant_id",
            );
            $q->whereIn("product_packages.variant_id", $guides);
        }
        return $q;
    }

    public function card(int $packageId, array $context = []): ?array
    {
        $p = $this->packages($context)
            ->where("product_packages.id", $packageId)
            ->first();
        if (!$p) {
            return null;
        }
        $image = DB::table("product_images")
            ->where("product_id", $p->product_id)
            ->orderByDesc("is_primary")
            ->orderBy("sort_order")
            ->orderBy("id")
            ->value("image_url");
        if ($image && !preg_match("~^https?://~i", $image)) {
            $image = asset("storage/" . ltrim($image, "/"));
        }
        $image = $image && preg_match("~^https?://~i", $image) ? $image : null;
        return [
            "package_id" => (int) $p->id,
            "product_id" => (int) $p->product_id,
            "variant_id" => (int) $p->variant_id,
            "name" => $p->product_name,
            "variant" => $p->variant_name,
            "sku" => $p->sku,
            "size" => (string) $p->size,
            "unit" => $p->unit,
            "price" => (string) $p->price,
            "available_to_sell" => (int) $p->available_to_sell,
            "image" => $image,
            "brand" => $p->brand,
            "description" => $this->text($p->description, 2500),
            "usage_instructions" => $this->text($p->usage_instructions, 1800),
            "safety_warning" => $this->text($p->safety_warning, 1500),
            "reason" => !empty($context["advisory"])
                ? "Có hướng dẫn phù hợp với điều kiện đang chọn."
                : "Thông tin hiện tại của cửa hàng.",
        ];
    }

    public function search(
        string $term,
        array $context,
        string $mode,
        AiRetrieval $retrieval,
    ): array {
        if (
            isset($context["max_price"]) &&
            ($context["budget_scope"] ?? "") !== "per_package"
        ) {
            return [
                "status" => "needs_clarification",
                "message" =>
                    "Ngân sách này cho mỗi quy cách hay tổng tiền cần mua?",
                "cards" => [],
            ];
        }
        $q = $this->packages($context);
        $term = trim($term);
        $keyword = clone $q;
        if ($term !== "") {
            $keyword->where(function ($q) use ($term) {
                $q->where("product_packages.sku", $term)
                    ->orWhere("ap.product_name", "like", "%" . $term . "%")
                    ->orWhere("av.variant_name", "like", "%" . $term . "%")
                    ->orWhere("ap.brand", "like", "%" . $term . "%");
            });
        }
        $order = $mode === "cheaper" ? "price" : "id";
        $ids = $keyword
            ->orderBy("product_packages." . $order)
            ->limit(30)
            ->pluck("product_packages.id")
            ->map(fn($id) => (int) $id)
            ->all();
        if ($mode === "semantic" && !$ids && !empty($context["advisory"])) {
            $ids = (clone $q)
                ->orderBy("product_packages.id")
                ->limit(30)
                ->pluck("product_packages.id")
                ->map(fn($id) => (int) $id)
                ->all();
        }
        if ($term !== "") {
            $exact = (clone $q)
                ->where("product_packages.sku", $term)
                ->pluck("product_packages.id")
                ->map(fn($id) => (int) $id)
                ->all();
            if ($exact) {
                $ids = $exact;
            }
        }
        $vectorIds = [];
        $degraded = false;
        if ($mode === "semantic" && $term !== "" && empty($exact)) {
            try {
                $vectorIds = $retrieval->packages($term);
            } catch (AiFault) {
                $degraded = true;
            }
        }
        // RRF k=60; điều kiện nghiệp vụ được áp dụng SAU hợp nhất, không dựa vào điểm để xác nhận công dụng.
        $scores = [];
        foreach ([$ids, $vectorIds] as $list) {
            foreach ($list as $i => $id) {
                $scores[$id] = ($scores[$id] ?? 0) + 1 / (60 + $i + 1);
            }
        }
        arsort($scores, SORT_NUMERIC);
        $candidates = array_keys($scores);
        $valid = $this->packages($context)
            ->whereIn("product_packages.id", $candidates)
            ->pluck("product_packages.id")
            ->map(fn($id) => (int) $id)
            ->all();
        $candidates = array_values(
            array_filter(
                $candidates,
                fn($id) => in_array((int) $id, $valid, true),
            ),
        );
        if ($mode === "cheaper") {
            $candidates = $this->packages($context)
                ->whereIn("product_packages.id", $candidates)
                ->orderBy("product_packages.price")
                ->orderBy("product_packages.id")
                ->pluck("product_packages.id")
                ->all();
        }
        $cards = [];
        foreach (array_slice($candidates, 0, 6) as $id) {
            if ($c = $this->card((int) $id, $context)) {
                $cards[] = $c;
            }
        }
        $status = $cards
            ? "ok"
            : (!empty($context["advisory"])
                ? "no_verified_match"
                : "no_match_for_filters");
        if (
            $cards &&
            !array_filter($cards, fn($c) => $c["available_to_sell"] > 0)
        ) {
            // Chỉ kết luận hết hàng khi SQL trên TOÀN BỘ tập thỏa bộ lọc xác nhận không còn quy cách nào bán được.
            $all = $this->packages($context);
            if ($term !== "" && $mode !== "semantic") {
                $all->where(
                    fn($q) => $q
                        ->where("product_packages.sku", $term)
                        ->orWhere("ap.product_name", "like", "%" . $term . "%")
                        ->orWhere("av.variant_name", "like", "%" . $term . "%")
                        ->orWhere("ap.brand", "like", "%" . $term . "%"),
                );
            }
            if (
                !DB::query()
                    ->fromSub($all->toBase(), "all_stock")
                    ->where("available_to_sell", ">", 0)
                    ->exists()
            ) {
                $status = "out_of_stock";
            }
        }
        return [
            "status" => $status,
            "cards" => $cards,
            "semantic_degraded" => $degraded,
            "message" => $degraded
                ? "Đang tìm bằng danh mục và từ khóa."
                : null,
        ];
    }

    public function text(?string $value, int $limit): string
    {
        return mb_substr(
            trim(
                preg_replace(
                    "/\s+/u",
                    " ",
                    html_entity_decode(
                        strip_tags($value ?? ""),
                        ENT_QUOTES | ENT_HTML5,
                        "UTF-8",
                    ),
                ),
            ),
            0,
            $limit,
        );
    }
}