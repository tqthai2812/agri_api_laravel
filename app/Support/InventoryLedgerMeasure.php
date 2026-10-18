<?php

namespace App\Support;

use Illuminate\Support\Collection;

final class InventoryLedgerMeasure
{
    public static function of(
        object $package,
        Collection $lots,
        Collection $transactions,
        Collection $allMovements,
        DashboardPeriod $period,
        string $asOf,
    ): array {
        $id = (int) $package->id;
        $issues = [];
        $fatal = false;
        $bad = function (string $message) use (&$issues, &$fatal) {
            $issues[$message] = $message;
            $fatal = true;
        };
        $tx = $transactions->keyBy("id");
        $byLot = $allMovements->where("lot_package_id", $id)->groupBy("lot_id");
        $byTx = $allMovements
            ->where("transaction_package_id", $id)
            ->groupBy("inventory_transaction_id");
        $coverage = null;
        $legacy = 0;
        foreach ($transactions as $t) {
            if (
                $t->document_type === "opening_balance" &&
                $t->document_status === "posted"
            ) {
                foreach ($byTx->get($t->id, collect()) as $m) {
                    if ($coverage === null || $m->occurred_at > $coverage) {
                        $coverage = $m->occurred_at;
                    }
                }
            }
        }
        foreach ($transactions as $t) {
            $ms = $byTx->get($t->id, collect());
            foreach ($ms as $m) {
                if ((int) $m->lot_package_id !== $id) {
                    $bad("Nhật ký lô thuộc khác SKU với giao dịch gốc.");
                }
            }
            if ($t->document_item_id === null) {
                $legacy++;
                $at = $t->occurred_at ?? $t->created_at;
                if (!$coverage || !$at || $at >= $coverage) {
                    $bad(
                        "Có giao dịch cũ chưa gắn chứng từ/lô sau mốc mở sổ hoặc chưa có mốc mở sổ.",
                    );
                }
                continue;
            }
            if (
                $t->document_status !== "posted" ||
                (int) $t->item_package_id !== $id ||
                (int) $t->item_change !== (int) $t->quantity_change
            ) {
                $bad("Giao dịch không khớp dòng chứng từ đã ghi sổ.");
            }
            if (
                $ms->isEmpty() ||
                (int) $ms->sum("quantity_change") !== (int) $t->quantity_change
            ) {
                $bad("Tổng thay đổi theo lô không khớp giao dịch kho.");
            }
            if (
                $t->occurred_at === null ||
                $t->quantity_before === null ||
                $t->quantity_after === null ||
                (int) $t->quantity_before + (int) $t->quantity_change !==
                (int) $t->quantity_after
            ) {
                $bad(
                    "Giao dịch thiếu thời điểm hoặc số dư trước/sau không hợp lệ.",
                );
            }
        }
        $r = [
            "opening" => 0,
            "receipts" => 0,
            "sale_issues" => 0,
            "adjustment_in" => 0,
            "adjustment_out" => 0,
            "opening_entries" => 0,
            "closing" => 0,
        ];
        $start = $period->storageStart();
        $end = $period->storageEnd();
        $lotTotal = 0;
        $movementTotal = 0;
        foreach ($lots as $lot) {
            $lotTotal += (int) $lot->quantity_on_hand;
            $ms = $byLot->get($lot->id, collect());
            $balance = 0;
            if ($ms->isEmpty()) {
                $bad(
                    "Có lô thiếu nhật ký mở đầu; không tự lấy tồn hiện tại làm tồn lịch sử.",
                );
            }
            foreach ($ms as $index => $m) {
                $movementTotal++;
                $t = $tx->get($m->inventory_transaction_id);
                $change = (int) $m->quantity_change;
                if (
                    !$t ||
                    (int) $m->transaction_package_id !== $id ||
                    (int) $m->lot_package_id !== $id
                ) {
                    $bad("Lô và giao dịch thuộc khác SKU.");
                    continue;
                }
                if (
                    (int) $m->quantity_before !== $balance ||
                    (int) $m->quantity_before + $change !==
                    (int) $m->quantity_after ||
                    (int) $m->quantity_after < 0
                ) {
                    $bad("Chuỗi số dư lô bị đứt hoặc số lượng trước/sau sai.");
                }
                $balance = (int) $m->quantity_after;
                if ($m->occurred_at > $asOf) {
                    $bad("Có nhật ký mang thời điểm tương lai.");
                }
                $type = $t->document_type;
                $valid =
                    $t->document_status === "posted" &&
                    match ($type) {
                        "supplier_receipt" => $change > 0,
                        "sale_issue" => $change < 0,
                        "adjustment" => $change !== 0,
                        "opening_balance" => $change > 0 && $index === 0,
                        default => false,
                    };
                if (!$valid) {
                    $bad(
                        "Loại chứng từ, trạng thái hoặc chiều nhập/xuất không hợp lệ.",
                    );
                    continue;
                }
                if ($m->occurred_at < $start) {
                    $r["opening"] += $change;
                }
                if ($m->occurred_at < $end) {
                    $r["closing"] += $change;
                }
                if ($m->occurred_at >= $start && $m->occurred_at < $end) {
                    $key = match ($type) {
                        "supplier_receipt" => "receipts",
                        "sale_issue" => "sale_issues",
                        "opening_balance" => "opening_entries",
                        default => $change > 0
                            ? "adjustment_in"
                            : "adjustment_out",
                    };
                    $r[$key] += abs($change);
                }
            }
            if ($balance !== (int) $lot->quantity_on_hand) {
                $bad("Số dư cuối nhật ký không khớp số lượng hiện tại của lô.");
            }
        }
        if ($lotTotal !== (int) $package->quantity_available) {
            $bad("Tồn vật lý SKU không khớp tổng tồn lô.");
        }
        if ($coverage && $start < $coverage) {
            $r["opening"] = null;
            $issues["coverage"] =
                "Kỳ bắt đầu trước lúc chuyển tồn cũ sang lô. Tồn đầu kỳ chưa xác định; cột Mở sổ là số dư đưa vào sổ lô.";
        }
        if ($coverage && $end <= $coverage) {
            $r["closing"] = null;
        }
        if ($fatal) {
            foreach (["opening", "closing"] as $k) {
                $r[$k] = null;
            }
        }
        return $r + [
            "coverage_start" => $coverage,
            "legacy_transactions" => $legacy,
            "movement_count" => $movementTotal,
            "complete" =>
            !$fatal && $r["opening"] !== null && $r["closing"] !== null,
            "issues" => array_values($issues),
        ];
    }
}
