<?php

namespace App\Services\Ai;

use App\Support\AiDecimal as D;

class AiQuantityCalculator
{
    public function __construct(private AiCatalog $catalog) {}

    public function calculate(array $args, array $context): array
    {
        if (empty($context["crop_id"]) || empty($context["purpose_id"])) {
            throw new AiFault(
                "needs_clarification",
                "Bạn xác nhận cây trồng và mục đích sử dụng trước nhé.",
            );
        }
        $context["advisory"] = true;
        $guide = $this->catalog->guide($args["guide_id"], $context);
        $card = $this->catalog->card($args["package_id"]);
        if (!$guide || !$card || $guide["variant_id"] !== $card["variant_id"]) {
            throw new AiFault(
                "no_verified_match",
                "Hướng dẫn hoặc quy cách không còn phù hợp với điều kiện đang chọn.",
            );
        }
        $matching = $this->catalog
            ->guides($context)
            ->where("variant_id", $card["variant_id"])
            ->count();
        if (
            isset($context["guide_id"]) &&
            $context["guide_id"] !== $guide["id"]
        ) {
            throw new AiFault(
                "no_verified_match",
                "Hướng dẫn tính không trùng lựa chọn của bạn.",
            );
        }
        if ($matching > 1 && ($context["guide_id"] ?? null) !== $guide["id"]) {
            throw new AiFault(
                "needs_clarification",
                "Có nhiều hướng dẫn khác điều kiện hoặc cách dùng. Bạn chọn hướng dẫn phù hợp trong danh sách trước nhé.",
            );
        }
        $kind = $guide["dose_unit"];
        if (!in_array($kind, ["g", "ml"], true)) {
            throw new AiFault(
                "insufficient_calculation_data",
                "Hướng dẫn này chưa có đơn vị liều lượng để tính.",
            );
        }
        $factor = match ($card["unit"]) {
            "kg" => $kind === "g" ? 1000 : null,
            "g" => $kind === "g" ? 1 : null,
            "l" => $kind === "ml" ? 1000 : null,
            "ml" => $kind === "ml" ? 1 : null,
            default => null,
        };
        if ($factor === null) {
            throw new AiFault(
                "insufficient_calculation_data",
                "Quy cách và liều dùng chưa có đơn vị tương thích để tính số lượng mua.",
            );
        }
        $area = isset($args["area_m2"]) ? D::scaled($args["area_m2"]) : null;
        $water = isset($args["water_l"]) ? D::scaled($args["water_l"]) : null;
        $target = $args["target"] ?? "total";
        $limits = [];
        if ($target === "one_tank" && $water === null) {
            throw new AiFault(
                "insufficient_calculation_data",
                "Bạn cho biết lượng dung dịch thực tế cần pha trong bình, tính bằng lít nhé.",
            );
        }
        if (
            $target === "one_tank" &&
            ($guide["dose_per_ha_min"] !== null ||
                $guide["water_l_per_ha_min"] !== null) &&
            ($args["area_scope"] ?? null) !== "one_tank"
        ) {
            throw new AiFault(
                "insufficient_calculation_data",
                "Cần biết diện tích được phun bởi riêng một bình này. Không dùng diện tích toàn ruộng làm diện tích cho một bình.",
            );
        }
        // Khi có giới hạn, bắt buộc có dữ liệu để kiểm tra giới hạn đó, kể cả câu hỏi một bình.
        if ($guide["dose_per_ha_min"] !== null) {
            if (!$area) {
                throw new AiFault(
                    "insufficient_calculation_data",
                    "Cần diện tích thực tế được phun để kiểm tra định mức theo hecta.",
                );
            }
            $limits[] = [
                [
                    D::mul(D::scaled($guide["dose_per_ha_min"]), $area),
                    "10000000000000000",
                ],
                [
                    D::mul(D::scaled($guide["dose_per_ha_max"]), $area),
                    "10000000000000000",
                ],
            ];
        }
        if ($guide["dose_per_liter_min"] !== null) {
            if (!$water) {
                throw new AiFault(
                    "insufficient_calculation_data",
                    "Cần tổng lượng dung dịch pha để kiểm tra định mức trên mỗi lít.",
                );
            }
            $limits[] = [
                [
                    D::mul(D::scaled($guide["dose_per_liter_min"]), $water),
                    "1000000000000",
                ],
                [
                    D::mul(D::scaled($guide["dose_per_liter_max"]), $water),
                    "1000000000000",
                ],
            ];
        }
        if ($guide["water_l_per_ha_min"] !== null) {
            if (!$area || !$water) {
                throw new AiFault(
                    "insufficient_calculation_data",
                    "Cần cả diện tích và tổng lượng dung dịch để kiểm tra lượng nước phun trên hecta.",
                );
            }
            $ratio = [D::mul($water, "10000"), $area];
            if (
                D::compare($ratio, [
                    D::scaled($guide["water_l_per_ha_min"]),
                    "1000000",
                ]) < 0 ||
                D::compare($ratio, [
                    D::scaled($guide["water_l_per_ha_max"]),
                    "1000000",
                ]) > 0
            ) {
                throw new AiFault(
                    "no_verified_match",
                    "Lượng nước phun trên diện tích này nằm ngoài hướng dẫn. Hãy kiểm tra lại dữ liệu và nhãn.",
                );
            }
        }
        if (!$limits) {
            throw new AiFault(
                "insufficient_calculation_data",
                "Hướng dẫn chỉ có nội dung chữ, chưa có định mức để tính.",
            );
        }
        [$min, $max] = $limits[0];
        foreach ($limits as [$lo, $hi]) {
            if (D::compare($lo, $min) > 0) {
                $min = $lo;
            }
            if (D::compare($hi, $max) < 0) {
                $max = $hi;
            }
        }
        if (D::compare($min, $max) > 0) {
            throw new AiFault(
                "no_verified_match",
                "Các giới hạn theo diện tích và nước không có khoảng giao nhau; chưa thể chốt lượng pha.",
            );
        }
        $size = D::mul(D::scaled($card["size"]), (string) $factor);
        $counts = [D::ceilPackages($min, $size), D::ceilPackages($max, $size)];
        $money = function (string $count) use ($card) {
            $cents = D::div(D::scaled($card["price"], false), "10000")[0];
            return D::fixed(D::mul($cents, $count), 2);
        };
        $precision = $min[0] === "0" ? true : D::render($min) === "0.000000";
        return [
            "status" =>
                D::cmp($counts[0], (string) $card["available_to_sell"]) > 0
                    ? "stock_insufficient"
                    : "ok",
            "target" => $target,
            "guide" => $guide,
            "package" => $card,
            "dose_unit" => $kind,
            "amount_min" => D::render($min),
            "amount_max" => D::render($max, true),
            "precision_limited" => $precision,
            "packages_min" => $counts[0],
            "packages_max" => $counts[1],
            "goods_total_min" => $money($counts[0]),
            "goods_total_max" => $money($counts[1]),
            "pre_harvest_days" => $guide["pre_harvest_days"],
            "note" =>
                "Đây là khoảng theo nhãn, chưa tự chọn một mức liều. Số quy cách mua làm tròn lên; lượng dùng không phải toàn bộ lượng trong chai. Tiền hàng chưa gồm vận chuyển và voucher.",
        ];
    }
}