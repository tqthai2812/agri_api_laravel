<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\DB;

class AiDocumentBuilder
{
    public function __construct(private AiCatalog $catalog) {}

    public function chunks(string $kind, int $id): array
    {
        if ($kind === "product") {
            $p = DB::table("products")
                ->where("id", $id)
                ->where("is_show", true)
                ->first();
            if (!$p) {
                return [];
            }
            $variants = DB::table("product_variants")
                ->where("product_id", $id)
                ->orderBy("id")
                ->pluck("variant_name")
                ->all();
            $header =
                "Sản phẩm: {$p->product_name}\nNhãn hiệu: " .
                ($p->brand ?? "Chưa có dữ liệu") .
                "\nBiến thể: " .
                implode(", ", $variants);
            $body =
                "Mô tả: " .
                $this->catalog->text($p->description, 25000) .
                "\nNội dung sử dụng chung: " .
                $this->catalog->text($p->usage_instructions, 20000) .
                "\nAn toàn: " .
                $this->catalog->text($p->safety_warning, 15000);
        } elseif ($kind === "guide") {
            $g = $this->catalog->guide($id);
            if (!$g) {
                return [];
            }
            $p = DB::table("products")->where("id", $g["product_id"])->first();
            $v = DB::table("product_variants")
                ->where("id", $g["variant_id"])
                ->first();
            $crop = DB::table("crops")->where("id", $g["crop_id"])->first();
            $purpose = DB::table("product_purposes")
                ->where("id", $g["purpose_id"])
                ->first();
            if (!$p || !$v || !$crop || !$purpose) {
                return [];
            }
            $header =
                "Hướng dẫn: {$p->product_name}; biến thể {$v->variant_name}.\nCây: {$g["crop"]}; tên khác: " .
                ($crop->aliases ?? "[]") .
                "\nCông dụng: {$g["purpose"]}; tên khác: " .
                ($purpose->aliases ?? "[]") .
                "\nPhạm vi giai đoạn: {$g["growth_stage_scope"]}; " .
                implode(", ", array_column($g["growth_stages"], "name")) .
                "\nCách dùng: {$g["application_method"]}\nNguồn: {$g["label_source"]}";
            foreach (
                [
                    "dose_unit",
                    "dose_per_ha_min",
                    "dose_per_ha_max",
                    "dose_per_liter_min",
                    "dose_per_liter_max",
                    "water_l_per_ha_min",
                    "water_l_per_ha_max",
                    "pre_harvest_days",
                ]
                as $key
            ) {
                $header .=
                    "\n{$key}: " .
                    ($g[$key] === null ? "Chưa có dữ liệu" : $g[$key]);
            }
            $body = "Điều kiện và hướng dẫn: {$g["instructions"]}\nCảnh báo: {$g["safety_warning"]}";
        } else {
            throw new \InvalidArgumentException("Unknown AI source");
        }
        // Không có giá/tồn trong nội dung embedding. Mỗi đoạn lặp ngữ cảnh để không mất điều kiện nguồn.
        $chunks = [];
        $size = max(600, (int) config("ai.chunk_chars", 2400));
        for ($i = 0; $i < max(1, mb_strlen($body)); $i += $size - 180) {
            $chunks[] = $header . "\n" . mb_substr($body, $i, $size);
        }
        return $chunks;
    }

    public function currentHash(string $kind, int $id, int $chunk): ?string
    {
        $text = $this->chunks($kind, $id)[$chunk] ?? null;
        return $text === null ? null : hash("sha256", $text);
    }

    public static function fingerprint(): string
    {
        return hash(
            "sha256",
            config("ai.embedding_model") .
                "|" .
                config("ai.dimensions") .
                "|" .
                config("ai.collection"),
        );
    }
}