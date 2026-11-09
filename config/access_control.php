<?php

return [
    // These are existing role names, not new application roles.
    "reserved_roles" => ["admin", "staff", "customer"],
    "sensitive_prefixes" => ["user.", "role.", "permission.", "settings."],
    "groups" => [
        "dashboard" => "Bảng điều khiển",
        "category" => "Danh mục",
        "subcategory" => "Danh mục con",
        "origin" => "Xuất xứ",
        "product" => "Sản phẩm",
        "order" => "Đơn hàng",
        "user" => "Người dùng",
        "role" => "Vai trò",
        "permission" => "Danh sách quyền",
        "gallery" => "Trưng bày",
        "article" => "Bài viết",
        "settings" => "Cài đặt",
        "inventory" => "Kho hàng",
        "delivery-method" => "Giao hàng",
        "discount" => "Voucher",
        "supplier" => "Nhà cung cấp",
        "review" => "Đánh giá",
        "contact" => "Liên hệ",
        "expense" => "Chi phí",
        "expense-category" => "Danh mục chi phí",
        "report" => "Báo cáo",
        "export" => "Tệp xuất",
    ],
    "actions" => [
        "view" => "Xem",
        "create" => "Tạo",
        "update" => "Cập nhật",
        "delete" => "Xóa",
        "assign-role" => "Gán vai trò cho",
        "assign-permission" => "Cấp quyền cho",
        "moderate" => "Kiểm duyệt",
        "reply" => "Trả lời",
        "manage" => "Quản lý",
        "post" => "Ghi sổ",
        "pay" => "Thanh toán",
        "reverse" => "Đảo phiếu",
        "export" => "Xuất",
    ],
    "labels" => [
        "report.profit.view" => "Xem báo cáo lợi nhuận",
        "report.profit.export" => "Xuất báo cáo lợi nhuận",
        "report.inventory.view" => "Xem báo cáo kho",
        "report.inventory.export" => "Xuất báo cáo kho",
        "user.assign-permission" => "Cấp quyền trực tiếp cho người dùng",
        "user.assign-role" => "Gán vai trò cho người dùng",
        "role.assign-permission" => "Gán quyền cho vai trò",
        "inventory.create" => "Nhập kho",
        "inventory.update" => "Cập nhật kho",
    ],
    "descriptions" => [
        "user.assign-role" =>
            "Gán hoặc thu hồi nhiều vai trò. Chỉ Admin được giao quyền quản trị.",
        "user.assign-permission" =>
            "Cấp quyền trực tiếp để bổ sung; bỏ chọn không phủ định quyền từ vai trò.",
        "role.assign-permission" =>
            "Đổi quyền của vai trò, ảnh hưởng tất cả tài khoản đang sử dụng vai trò đó.",
        "report.profit.view" =>
            "Xem doanh thu, giảm giá, giá vốn và lợi nhuận.",
        "report.inventory.view" => "Xem tồn kho và dữ liệu lô hàng.",
    ],
];