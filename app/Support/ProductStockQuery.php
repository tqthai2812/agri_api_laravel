<?php

namespace App\Support;

use App\Models\ProductPackage;
use Illuminate\Database\Eloquent\Builder;

final class ProductStockQuery
{
    /**
     * Bổ sung available_to_sell vào query của ProductPackage.
     *
     * Query phải sử dụng bảng product_packages, không đổi alias bảng.
     * Gọi sau select() nếu query có chọn danh sách cột riêng.
     */
    public static function apply(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('product_packages.*');
        }

        return $query->selectRaw(
            <<<'SQL'
                GREATEST(
                    0,
                    COALESCE(
                        (
                            SELECT SUM(l.quantity_on_hand)
                            FROM inventory_lots AS l
                            WHERE l.package_id = product_packages.id
                              AND l.status = ?
                              AND (
                                  l.expires_on IS NULL
                                  OR l.expires_on >= ?
                              )
                        ),
                        0
                    )
                    -
                    COALESCE(
                        (
                            SELECT SUM(r.quantity)
                            FROM stock_reservations AS r
                            WHERE r.package_id = product_packages.id
                              AND r.status = ?
                        ),
                        0
                    )
                ) AS available_to_sell
            SQL,
            [
                'available',
                now()->toDateString(),
                'active',
            ]
        );
    }

    public static function packages(): Builder
    {
        return self::apply(ProductPackage::query());
    }
}
