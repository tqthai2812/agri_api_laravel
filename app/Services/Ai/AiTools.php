<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AiTools
{
    public array $cards = [];
    public array $guides = [];
    public array $cardContexts = [];
    public array $calculationArgs = [];
    public bool $lastAdvisory = false;
    public array $calculations = [];
    public array $options = [];
    public array $comparison = [];
    public array $context = [];
    public array $lastPackages = [];
    public string $lastQuery = "";
    public bool $readBusiness = false;

    public function __construct(
        private AiCatalog $catalog,
        private AiRetrieval $retrieval,
        private AiQuantityCalculator $calculator,
    ) {}

    public function declarations(): array
    {
        $s = fn(string $description = "") => [
            "type" => "STRING",
            "description" => $description,
        ];
        $i = fn() => ["type" => "INTEGER"];
        $a = fn(array $items) => ["type" => "ARRAY", "items" => $items];
        $ctx = [
            "type" => "OBJECT",
            "description" =>
                "Chỉ cập nhật điều kiện khách đã nói/xác nhận; null để bỏ điều kiện. Đổi cây tự bỏ giai đoạn cũ. Không suy giai đoạn thành công dụng.",
            "properties" => [
                "guide_id" => ["type" => "INTEGER", "nullable" => true],
                "crop_id" => ["type" => "INTEGER", "nullable" => true],
                "purpose_id" => ["type" => "INTEGER", "nullable" => true],
                "growth_stage_id" => ["type" => "INTEGER", "nullable" => true],
                "category_id" => ["type" => "INTEGER", "nullable" => true],
                "unit_type" => [
                    "type" => "STRING",
                    "enum" => ["liquid", "solid", "any"],
                    "nullable" => true,
                ],
                "max_price" => ["type" => "STRING", "nullable" => true],
                "budget_scope" => [
                    "type" => "STRING",
                    "enum" => ["per_package", "total", "unclear"],
                    "nullable" => true,
                ],
            ],
        ];
        $defs = [
            [
                "get_advisory_options",
                "Đọc danh mục cây/công dụng/giai đoạn/danh mục sản phẩm. Trả tất cả tên khớp và alias để làm rõ, không tự chọn khi mơ hồ.",
                [
                    "kind" => [
                        "type" => "STRING",
                        "enum" => [
                            "crop",
                            "purpose",
                            "growth_stage",
                            "category",
                        ],
                    ],
                    "search" => $s(
                        "Tên hoặc alias để tìm; chuỗi rỗng để xem danh sách.",
                    ),
                    "crop_id" => $i(),
                ],
                ["kind"],
            ],
            [
                "set_advisory_context",
                "Ghi nhớ điều kiện ĐÃ XÁC NHẬN. Đổi cây bỏ giai đoạn cũ; công dụng phải do khách nói riêng. Ngân sách mơ hồ phải hỏi mỗi quy cách hay tổng tiền.",
                ["patch" => $ctx],
                ["patch"],
            ],
            [
                "search_products",
                "Tìm quy cách công khai, giá và tồn bán được hiện tại. advisory=true chỉ khi tư vấn sử dụng theo cây/công dụng; tra tên/SKU chung dùng false. cheaper giữ query đã tìm và bộ lọc. semantic dùng cho mô tả tự nhiên.",
                [
                    "query" => $s(),
                    "advisory" => ["type" => "BOOLEAN"],
                    "mode" => [
                        "type" => "STRING",
                        "enum" => ["keyword", "semantic", "cheaper"],
                    ],
                ],
                ["query", "advisory", "mode"],
            ],
            [
                "get_product_details",
                "Đọc lại quy cách theo package_id, hoặc thứ tự 1-based trong DANH SÁCH VỪA TRẢ ở Cache. Không dùng vị trí tự suy từ mô tả.",
                [
                    "package_id" => $i(),
                    "ordinal" => $i(),
                ],
                [],
            ],
            [
                "get_usage_guide",
                "Lấy hướng dẫn bật đúng biến thể/cây/công dụng/giai đoạn; không lấy từ các hướng dẫn khác để ghép điều kiện.",
                [
                    "variant_id" => $i(),
                    "guide_id" => $i(),
                    "page" => $i(),
                    "method_search" => $s(
                        "Từ khóa cách dùng để xem đúng nhóm hướng dẫn; khi has_more=true có thể đọc trang tiếp theo.",
                    ),
                ],
                [],
            ],
            [
                "compare_products",
                "Đọc lại và so sánh từ 2 đến 4 package_id có thật; đọc đúng danh sách khi khách nói hai loại vừa nói.",
                [
                    "package_ids" => $a($i()),
                ],
                ["package_ids"],
            ],
            [
                "calculate_required_quantity",
                "Tính bằng code từ guide_id và package_id. Giá trị diện tích m², nước lít dạng chuỗi số dương. Không suy tổng nước từ dung tích bình; thiếu dữ liệu hãy hỏi. Không tự chọn liều ở giữa khoảng.",
                [
                    "guide_id" => $i(),
                    "package_id" => $i(),
                    "area_m2" => $s(),
                    "water_l" => $s(),
                    "area_scope" => [
                        "type" => "STRING",
                        "enum" => ["total", "one_tank"],
                        "description" =>
                            "Với target=one_tank, chỉ đặt one_tank khi khách xác nhận diện tích phun của riêng một bình.",
                    ],
                    "target" => [
                        "type" => "STRING",
                        "enum" => ["total", "one_tank"],
                    ],
                ],
                ["guide_id", "package_id", "target"],
            ],
            [
                "finish_response",
                "Hoàn tất câu trả lời tiếng Việt. Chỉ chọn card/option đã lấy từ công cụ. Backend dựng số liệu/thẻ/nguồn. Không tự điền tên, giá, liều hoặc số tồn vào lời diễn đạt. Nếu thiếu dữ liệu thì hỏi rõ một câu.",
                [
                    "text" => $s(),
                    "status" => [
                        "type" => "STRING",
                        "enum" => [
                            "ok",
                            "needs_clarification",
                            "no_catalog_match",
                            "no_verified_match",
                            "no_match_for_filters",
                            "out_of_stock",
                            "stock_insufficient",
                            "insufficient_calculation_data",
                            "out_of_scope",
                        ],
                    ],
                    "package_ids" => $a($i()),
                    "option_tokens" => $a($s()),
                ],
                ["text", "status"],
            ],
        ];
        return array_map(
            fn($d) => [
                "name" => $d[0],
                "description" => $d[1],
                "parameters" => [
                    "type" => "OBJECT",
                    "properties" => (object) $d[2],
                    "required" => $d[3],
                ],
            ],
            $defs,
        );
    }

    public function run(string $name, array $args): array
    {
        $rules = match ($name) {
            "get_advisory_options" => [
                "kind" => "required|in:crop,purpose,growth_stage,category",
                "search" => "sometimes|string|max:100",
                "crop_id" => "sometimes|integer|min:1",
            ],
            "set_advisory_context" => ["patch" => "required|array"],
            "search_products" => [
                "query" => "present|string|max:250",
                "advisory" => "required|boolean",
                "mode" => "required|in:keyword,semantic,cheaper",
            ],
            "get_product_details" => [
                "package_id" => "sometimes|integer|min:1",
                "ordinal" => "sometimes|integer|min:1|max:6",
            ],
            "get_usage_guide" => [
                "variant_id" => "sometimes|integer|min:1",
                "guide_id" => "sometimes|integer|min:1",
                "page" => "sometimes|integer|min:1|max:100",
                "method_search" => "sometimes|string|max:100",
            ],
            "compare_products" => [
                "package_ids" => "required|array|min:2|max:4",
                "package_ids.*" => "required|integer|min:1|distinct",
            ],
            "calculate_required_quantity" => [
                "guide_id" => "required|integer|min:1",
                "package_id" => "required|integer|min:1",
                "area_m2" => "sometimes|string|max:24",
                "water_l" => "sometimes|string|max:24",
                "area_scope" => "sometimes|in:total,one_tank",
                "target" => "required|in:total,one_tank",
            ],
            default => throw new AiFault(
                "invalid_input",
                "Công cụ không được hỗ trợ.",
            ),
        };
        if (
            array_diff(
                array_keys($args),
                array_keys(
                    array_filter(
                        $rules,
                        fn($key) => !str_contains($key, "."),
                        ARRAY_FILTER_USE_KEY,
                    ),
                ),
            )
        ) {
            throw new AiFault("invalid_input", "Tham số công cụ chưa hợp lệ.");
        }
        $v = Validator::make($args, $rules);
        if ($v->fails()) {
            throw new AiFault(
                "invalid_input",
                "Thiếu hoặc sai tham số công cụ. Hãy hỏi lại thông tin cần thiết.",
            );
        }
        $args = $v->validated();
        if ($name === "set_advisory_context") {
            $old = $this->context;
            $this->context = $this->catalog->mergeContext(
                $this->context,
                $args["patch"],
            );
            foreach (
                ["crop_id", "purpose_id", "growth_stage_id", "category_id"]
                as $key
            ) {
                if (($old[$key] ?? null) !== ($this->context[$key] ?? null)) {
                    $this->lastPackages = [];
                    $this->lastQuery = "";
                    if (
                        empty($this->context["crop_id"]) ||
                        empty($this->context["purpose_id"])
                    ) {
                        $this->lastAdvisory = false;
                    }
                    $this->cards = [];
                    $this->cardContexts = [];
                    $this->guides = [];
                    $this->comparison = [];
                    $this->calculations = [];
                    $this->calculationArgs = [];
                    break;
                }
            }
            return ["status" => "ok", "context" => $this->context];
        }
        if ($name === "get_advisory_options") {
            $list = $this->catalog->options(
                $args["kind"],
                $args["search"] ?? "",
                $args["crop_id"] ?? ($this->context["crop_id"] ?? null),
            );
            $options = [];
            foreach ($list as $row) {
                $row["token"] = (string) Str::uuid();
                $this->options[$row["token"]] = $row;
                $options[] = $row;
            }
            return [
                "status" => $options ? "ok" : "no_catalog_match",
                "options" => $options,
                "ambiguous" => count($options) > 1,
            ];
        }
        if ($name === "search_products") {
            if ($args["mode"] === "cheaper") {
                if (!$this->lastPackages) {
                    throw new AiFault(
                        "needs_clarification",
                        "Bạn muốn tìm rẻ hơn so với quy cách nào?",
                    );
                }
                $args["advisory"] = $args["advisory"] || $this->lastAdvisory;
            }
            if (
                $args["advisory"] &&
                (empty($this->context["crop_id"]) ||
                    empty($this->context["purpose_id"]))
            ) {
                throw new AiFault(
                    "needs_clarification",
                    "Cần xác nhận cây trồng và mục đích sử dụng; không suy từ giai đoạn sinh trưởng.",
                );
            }
            if ($args["mode"] === "cheaper") {
                $this->lastAdvisory = (bool) $args["advisory"];
            } else {
                $this->lastAdvisory = (bool) $args["advisory"];
            }
            $context = $this->context + ["advisory" => $args["advisory"]];
            if ($args["mode"] === "cheaper") {
                $args["query"] = $this->lastQuery;
                if ($this->lastPackages) {
                    $old = $this->catalog->card($this->lastPackages[0]);
                    if (!$old) {
                        throw new AiFault(
                            "no_match_for_filters",
                            "Quy cách vừa xem không còn công khai. Mình cần tìm lại trước khi so sánh giá.",
                        );
                    }
                    if ($old) {
                        $context["max_price"] =
                            isset($context["max_price"]) &&
                            \App\Support\AiDecimal::cmp(
                                \App\Support\AiDecimal::scaled(
                                    $context["max_price"],
                                ),
                                \App\Support\AiDecimal::scaled($old["price"]),
                            ) < 0
                                ? $context["max_price"]
                                : $old["price"];
                        $context["budget_scope"] = "per_package";
                    }
                }
            } else {
                $this->lastQuery = $args["query"];
            }
            $result = $this->catalog->search(
                $args["query"],
                $context,
                $args["mode"],
                $this->retrieval,
            );
            $this->lastPackages = array_column($result["cards"], "package_id");
            if ($args["mode"] === "cheaper" && isset($old)) {
                $result["cards"] = array_values(
                    array_filter(
                        $result["cards"],
                        fn($c) => \App\Support\AiDecimal::cmp(
                            \App\Support\AiDecimal::scaled($c["price"], false),
                            \App\Support\AiDecimal::scaled(
                                $old["price"],
                                false,
                            ),
                        ) < 0,
                    ),
                );
            }
            if (!$result["cards"] && $result["status"] === "ok") {
                $result["status"] = "no_match_for_filters";
            }
            $this->lastPackages = array_column($result["cards"], "package_id");
            foreach ($result["cards"] as $c) {
                $this->cards[$c["package_id"]] = $c;
                $this->cardContexts[$c["package_id"]] = $context;
            }
            $this->readBusiness = true;
            return $result;
        }
        if ($name === "get_product_details") {
            $id =
                $args["package_id"] ??
                ($this->lastPackages[($args["ordinal"] ?? 0) - 1] ?? 0);
            $card = $this->catalog->card((int) $id);
            if (!$card) {
                throw new AiFault(
                    "no_match_for_filters",
                    "Quy cách không còn công khai hoặc chưa xác định được.",
                );
            }
            $this->cards[$id] = $card;
            $this->readBusiness = true;
            return ["status" => "ok", "card" => $card];
        }
        if ($name === "get_usage_guide") {
            if (
                empty($this->context["crop_id"]) ||
                empty($this->context["purpose_id"])
            ) {
                throw new AiFault(
                    "needs_clarification",
                    "Bạn muốn dùng cho cây gì và mục đích nào?",
                );
            }
            $context = $this->context + ["advisory" => true];
            $q = $this->catalog->guides($context);
            if (!empty($args["variant_id"])) {
                $q->where("variant_id", $args["variant_id"]);
            }
            if (!empty($args["guide_id"])) {
                $q->whereKey($args["guide_id"]);
            }
            if (empty($args["variant_id"]) && empty($args["guide_id"])) {
                throw new AiFault(
                    "needs_clarification",
                    "Bạn muốn xem hướng dẫn của biến thể nào?",
                );
            }
            $total = (clone $q)->count();
            if (!empty($args["method_search"])) {
                $q->where(
                    "application_method",
                    "like",
                    "%" . $args["method_search"] . "%",
                );
            }
            $page = (int) ($args["page"] ?? 1);
            $rows = $q
                ->orderBy("product_usage_guides.id")
                ->offset(($page - 1) * 5)
                ->limit(6)
                ->get();
            $hasMore = $rows->count() > 5;
            $guides = [];
            foreach ($rows->take(5) as $row) {
                $g = $this->catalog->guide($row->id, $context);
                $this->guides[$g["id"]] = $g;
                $guides[] = $g;
            }
            $options = [];
            if (count($guides) > 0) {
                foreach ($guides as $g) {
                    $o = [
                        "id" => $g["id"],
                        "name" =>
                            $g["application_method"] .
                            " · " .
                            ($g["growth_stage_scope"] === "all"
                                ? "Mọi giai đoạn"
                                : implode(
                                    ", ",
                                    array_column($g["growth_stages"], "name"),
                                )),
                        "kind" => "guide",
                        "token" => (string) Str::uuid(),
                    ];
                    $this->options[$o["token"]] = $o;
                    $options[] = $o;
                }
            }
            $this->readBusiness = true;
            return [
                "status" => $guides ? "ok" : "no_verified_match",
                "guides" => $guides,
                "requires_choice" => $total > 1,
                "page" => $page,
                "has_more" => $hasMore,
                "options" => $options,
            ];
        }
        if ($name === "compare_products") {
            $this->comparison = [];
            foreach ($args["package_ids"] as $id) {
                $c = $this->catalog->card($id);
                if (!$c) {
                    throw new AiFault(
                        "no_match_for_filters",
                        "Một quy cách không còn công khai.",
                    );
                }
                $this->cards[$id] = $c;
                $row = $c;
                $row["guides"] = [];
                if (
                    !empty($this->context["crop_id"]) &&
                    !empty($this->context["purpose_id"])
                ) {
                    foreach (
                        $this->catalog
                            ->guides($this->context + ["advisory" => true])
                            ->where("variant_id", $c["variant_id"])
                            ->limit(3)
                            ->get()
                        as $g
                    ) {
                        $row["guides"][] = $this->catalog->guide(
                            $g->id,
                            $this->context + ["advisory" => true],
                        );
                    }
                }
                $row["composition"] =
                    "Chưa có trường thành phần cấu trúc; xem mô tả/nhãn gốc, không tự suy diễn.";
                $this->comparison[] = $row;
            }
            $this->readBusiness = true;
            return ["status" => "ok", "comparison" => $this->comparison];
        }
        $calculation = $this->calculator->calculate($args, $this->context);
        $this->calculationArgs[] = $args;
        $this->calculations[] = $calculation;
        $c = $calculation["package"];
        $this->cards[$c["package_id"]] = $c;
        $this->guides[$calculation["guide"]["id"]] = $calculation["guide"];
        $this->readBusiness = true;
        return $calculation;
    }

    public function finish(array $args): array
    {
        $v = Validator::make($args, [
            "text" => "required|string|max:3500",
            "status" =>
                "required|in:ok,needs_clarification,no_catalog_match,no_verified_match,no_match_for_filters,out_of_stock,stock_insufficient,insufficient_calculation_data,out_of_scope",
            "package_ids" => "sometimes|array|max:6",
            "package_ids.*" => "integer|min:1|distinct",
            "option_tokens" => "sometimes|array|max:12",
            "option_tokens.*" => "string|uuid|distinct",
        ]);
        if ($v->fails()) {
            throw new AiFault("invalid_input", "Câu trả lời chưa hợp lệ.");
        }
        if ($args["status"] === "ok" && !$this->readBusiness) {
            throw new AiFault(
                "needs_clarification",
                "Cần đọc công cụ trước khi trả thông tin nghiệp vụ.",
            );
        }
        $cards = [];
        foreach ($args["package_ids"] ?? [] as $id) {
            if (!isset($this->cards[$id])) {
                throw new AiFault(
                    "invalid_input",
                    "Chỉ được chọn quy cách đã đọc.",
                );
            }
            if (
                $c = $this->catalog->card(
                    $id,
                    !empty($this->cardContexts[$id]["advisory"])
                        ? $this->context + ["advisory" => true]
                        : [],
                )
            ) {
                $cards[] = $c;
            } else {
                throw new AiFault(
                    "no_verified_match",
                    "Một quy cách không còn phù hợp hoặc không còn công khai.",
                );
            }
        }
        $options = [];
        foreach ($args["option_tokens"] ?? [] as $token) {
            if (!isset($this->options[$token])) {
                throw new AiFault(
                    "invalid_input",
                    "Lựa chọn chưa được lấy từ danh mục.",
                );
            }
            $options[] = $this->options[$token];
        }
        foreach ($this->guides as $id => $old) {
            $fresh = $this->catalog->guide(
                (int) $id,
                $this->context + ["advisory" => true],
            );
            if (!$fresh) {
                throw new AiFault(
                    "no_verified_match",
                    "Hướng dẫn vừa thay đổi, cần tìm lại.",
                );
            }
            $this->guides[$id] = $fresh;
        }
        $this->calculations = array_map(
            fn($a) => $this->calculator->calculate($a, $this->context),
            $this->calculationArgs,
        );
        foreach ($this->comparison as &$row) {
            $fresh = $this->catalog->card($row["package_id"]);
            if (!$fresh) {
                throw new AiFault(
                    "no_match_for_filters",
                    "Quy cách vừa thay đổi, cần so sánh lại.",
                );
            }
            $row = array_replace($row, $fresh);
            $row["guides"] = array_values(
                array_filter(
                    array_map(
                        fn($g) => $this->catalog->guide(
                            $g["id"],
                            $this->context + ["advisory" => true],
                        ),
                        $row["guides"],
                    ),
                ),
            );
        }
        unset($row);
        if ($cards) {
            $this->lastPackages = array_column($cards, "package_id");
        }
        return [
            "id" => (string) Str::uuid(),
            "role" => "assistant",
            "text" => $args["text"],
            "status" => $args["status"],
            "cards" => $cards,
            "guides" => array_values($this->guides),
            "calculations" => $this->calculations,
            "comparison" => $this->comparison,
            "options" => $options,
            "created_at" => now()->toIso8601String(),
        ];
    }
}