<?php
// Chỉ dùng SQLite :memory: và API giả lập; không bootstrap app thật hoặc đọc .env.
require __DIR__ . "/Support/AiFixture.php";
use App\Services\Ai\{
    AiCatalog,
    AiQuantityCalculator,
    AiFault,
    AiChatEngine,
    AiChatSessions,
    AiIndexCoordinator,
    AiDocumentBuilder,
    AiRetrieval,
    AiTools,
    GeminiClient,
    QdrantClient,
    AiBudget,
};
use App\Jobs\Ai\IndexAiDocument;
use App\Support\AiDecimal as D;
use Illuminate\Support\Facades\{DB, Http, Cache, Facade};
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Str;
$results = [];
$assertions = 0;
function eq($a, $b, $message = "")
{
    global $assertions;
    $assertions++;
    if ($a !== $b) {
        throw new RuntimeException(
            $message .
                " expected " .
                json_encode($b, JSON_UNESCAPED_UNICODE) .
                " got " .
                json_encode($a, JSON_UNESCAPED_UNICODE),
        );
    }
}
function yes($a, $message = "")
{
    eq((bool) $a, true, $message);
}
function fault(callable $f, string $status)
{
    try {
        $f();
    } catch (AiFault $e) {
        eq($e->status, $status);
        return;
    }
    throw new RuntimeException("Expected " . $status);
}
function test(string $name, callable $f)
{
    global $results;
    try {
        $f();
        $results[] = ["name" => $name, "passed" => true];
    } catch (Throwable $e) {
        $results[] = [
            "name" => $name,
            "passed" => false,
            "error" => get_class($e) . ": " . $e->getMessage(),
            "location" => $e->getFile() . ":" . $e->getLine(),
        ];
    }
}
function fakeHttp($callback)
{
    global $app;
    $app->instance(Factory::class, new Factory());
    Facade::clearResolvedInstance(Factory::class);
    Http::fake($callback);
}
$catalog = app(AiCatalog::class);
$calculator = app(AiQuantityCalculator::class);
$context = ["crop_id" => 1, "purpose_id" => 1];
$calcArgs = [
    "guide_id" => 1,
    "package_id" => 1,
    "target" => "total",
    "area_m2" => "2000",
];
test(
    "Decimal multiplication preserves large values",
    fn() => eq(
        D::mul("99999999999999", "100000000000000"),
        "9999999999999900000000000000",
    ),
);
test("Decimal divide and ceiling on exact rational", function () {
    eq(D::div("100000001", "1000000"), ["100", "1"]);
    eq(D::ceilPackages(["100000001", "1000000"], "100000000"), "2");
    eq(D::ceilPackages(["100", "1"], "100000000"), "1");
});
test("Reject exponent, comma, negative, zero input", function () {
    foreach (["1e3", "2,5", "-1", "0"] as $v) {
        fault(fn() => D::scaled($v), "invalid_input");
    }
});
test("ATS uses eligible lots minus active holds", function () use ($catalog) {
    $c = $catalog->card(1);
    eq($c["available_to_sell"], 6);
    yes(!isset($c["quantity_available"]));
    yes(!isset($c["cost_price"]));
});
test("Hidden product never has public card", function () use ($catalog) {
    DB::table("products")
        ->where("id", 1)
        ->update(["is_show" => false]);
    eq($catalog->card(1), null);
    DB::table("products")
        ->where("id", 1)
        ->update(["is_show" => true]);
});
test("Alias ambiguity returns both catalog choices", function () use (
    $catalog,
) {
    eq(count($catalog->options("crop", "bắp-test")), 2);
});
test("Crop change clears incompatible stage", function () use ($catalog) {
    $next = $catalog->mergeContext(
        ["crop_id" => 1, "growth_stage_id" => 1, "purpose_id" => 1],
        ["crop_id" => 2],
    );
    yes(!isset($next["growth_stage_id"]));
    eq($next["purpose_id"], 1);
});
test(
    "Stage from another crop rejected",
    fn() => fault(
        fn() => $catalog->mergeContext(
            ["crop_id" => 1],
            ["growth_stage_id" => 2],
        ),
        "no_catalog_match",
    ),
);
test("Unknown scope is not all stages", function () use ($catalog) {
    DB::table("product_usage_guides")
        ->where("id", 1)
        ->update(["growth_stage_scope" => "unspecified"]);
    eq(
        $catalog
            ->guides([
                "crop_id" => 1,
                "purpose_id" => 1,
                "growth_stage_id" => 1,
            ])
            ->count(),
        0,
    );
    eq(
        $catalog
            ->guides(["crop_id" => 1, "purpose_id" => 1, "advisory" => true])
            ->count(),
        0,
    );
    DB::table("product_usage_guides")
        ->where("id", 1)
        ->update(["growth_stage_scope" => "all"]);
});
test("Conditions must belong to same guide and variant", function () use (
    $catalog,
) {
    $g = (array) DB::table("product_usage_guides")->where("id", 1)->first();
    unset($g["id"]);
    $g["crop_id"] = 2;
    $g["purpose_id"] = 2;
    DB::table("product_usage_guides")->insert($g);
    eq(
        $catalog
            ->packages(["crop_id" => 1, "purpose_id" => 2, "advisory" => true])
            ->count(),
        0,
    );
    eq(
        $catalog
            ->packages(["crop_id" => 1, "purpose_id" => 1, "advisory" => true])
            ->where("product_packages.id", 3)
            ->count(),
        0,
    );
});
test("Disabled guide never participates", function () use ($catalog) {
    DB::table("product_usage_guides")
        ->where("id", 1)
        ->update(["is_active" => false]);
    eq($catalog->guide(1), null);
    DB::table("product_usage_guides")
        ->where("id", 1)
        ->update(["is_active" => true]);
});
test(
    "Area interval, ceil packages, exact money and unknown isolation",
    function () use ($calculator, $calcArgs, $context) {
        $c = $calculator->calculate($calcArgs, $context);
        eq($c["amount_min"], "50.000000");
        eq($c["amount_max"], "100.000000");
        eq($c["packages_min"], "1");
        eq($c["packages_max"], "1");
        eq($c["goods_total_min"], "120000.00");
        eq($c["pre_harvest_days"], null);
    },
);
test("One millionth above package boundary buys next package", function () use (
    $calculator,
    $calcArgs,
    $context,
) {
    DB::table("product_usage_guides")
        ->where("id", 1)
        ->update(["dose_per_ha_max" => "500.000005"]);
    $c = $calculator->calculate($calcArgs, $context);
    eq($c["amount_max"], "100.000001");
    eq($c["packages_max"], "2");
    DB::table("product_usage_guides")
        ->where("id", 1)
        ->update(["dose_per_ha_max" => "500.000000"]);
});
test("Water and area bounds intersect, never average", function () use (
    $calculator,
    $calcArgs,
    $context,
) {
    DB::table("product_usage_guides")
        ->where("id", 1)
        ->update([
            "dose_per_liter_min" => "0.100000",
            "dose_per_liter_max" => "0.200000",
        ]);
    $c = $calculator->calculate($calcArgs + ["water_l" => "300"], $context);
    eq($c["amount_min"], "50.000000");
    eq($c["amount_max"], "60.000000");
    fault(
        fn() => $calculator->calculate($calcArgs, $context),
        "insufficient_calculation_data",
    );
    DB::table("product_usage_guides")
        ->where("id", 1)
        ->update(["dose_per_liter_min" => null, "dose_per_liter_max" => null]);
});
test("Water per hectare checked simultaneously", function () use (
    $calculator,
    $calcArgs,
    $context,
) {
    DB::table("product_usage_guides")
        ->where("id", 1)
        ->update([
            "water_l_per_ha_min" => "1000.000000",
            "water_l_per_ha_max" => "2000.000000",
        ]);
    fault(
        fn() => $calculator->calculate(
            $calcArgs + ["water_l" => "20"],
            $context,
        ),
        "no_verified_match",
    );
    $c = $calculator->calculate($calcArgs + ["water_l" => "300"], $context);
    eq($c["amount_min"], "50.000000");
    DB::table("product_usage_guides")
        ->where("id", 1)
        ->update(["water_l_per_ha_min" => null, "water_l_per_ha_max" => null]);
});
test("Incompatible units and piece refused", function () use (
    $calculator,
    $calcArgs,
    $context,
) {
    foreach (["kg", "piece"] as $unit) {
        DB::table("product_packages")
            ->where("id", 1)
            ->update(["unit" => $unit]);
        fault(
            fn() => $calculator->calculate($calcArgs, $context),
            "insufficient_calculation_data",
        );
    }
    DB::table("product_packages")
        ->where("id", 1)
        ->update(["unit" => "ml"]);
});
test(
    "Wrong variant is not usable for calculation",
    fn() => fault(
        fn() => $calculator->calculate(
            array_replace($calcArgs, ["package_id" => 3]),
            $context,
        ),
        "no_verified_match",
    ),
);
test(
    "One tank does not infer total water or area",
    fn() => fault(
        fn() => $calculator->calculate(
            [
                "guide_id" => 1,
                "package_id" => 1,
                "target" => "one_tank",
                "water_l" => "16",
            ],
            $context,
        ),
        "insufficient_calculation_data",
    ),
);
test("Text-only guide is valid but not calculable", function () use (
    $calculator,
    $calcArgs,
    $context,
) {
    DB::table("product_usage_guides")
        ->where("id", 1)
        ->update([
            "dose_per_ha_min" => null,
            "dose_per_ha_max" => null,
            "dose_unit" => null,
        ]);
    fault(
        fn() => $calculator->calculate($calcArgs, $context),
        "insufficient_calculation_data",
    );
    DB::table("product_usage_guides")
        ->where("id", 1)
        ->update([
            "dose_per_ha_min" => "250.000000",
            "dose_per_ha_max" => "500.000000",
            "dose_unit" => "ml",
        ]);
});
test("Insufficient stock separated from unknown quantities", function () use (
    $calculator,
    $calcArgs,
    $context,
) {
    $c = $calculator->calculate(
        array_replace($calcArgs, ["area_m2" => "100000"]),
        $context,
    );
    eq($c["status"], "stock_insufficient");
    eq($c["packages_min"], "25");
});
test("Document hash includes context but no price or stock", function () {
    $b = app(AiDocumentBuilder::class);
    $before = $b->currentHash("product", 1, 0);
    DB::table("product_packages")
        ->where("id", 1)
        ->update(["price" => "123456.78"]);
    eq($b->currentHash("product", 1, 0), $before);
    DB::table("product_packages")
        ->where("id", 1)
        ->update(["price" => "120000.00"]);
    $text = $b->chunks("guide", 1)[0];
    yes(str_contains($text, "fixture://"));
    yes(str_contains($text, "Công dụng thử A"));
    yes(!str_contains($text, "120000.00"));
});
test(
    "Index jobs only dispatched after commit; rollback has no job",
    function () {
        $before = DB::table("jobs")->count();
        DB::beginTransaction();
        app(AiIndexCoordinator::class)->touch("product", 1);
        eq(DB::table("jobs")->count(), $before);
        DB::rollBack();
        eq(DB::table("jobs")->count(), $before);
        DB::beginTransaction();
        app(AiIndexCoordinator::class)->touch("guide", 1);
        eq(DB::table("jobs")->count(), $before);
        DB::commit();
        yes(DB::table("jobs")->count() > $before);
    },
);
test("Build source creates exact twelve-column documents", function () {
    app(AiIndexCoordinator::class)->build(
        "guide",
        1,
        app(AiDocumentBuilder::class),
    );
    $doc = DB::table("ai_knowledge_documents")
        ->where("usage_guide_id", 1)
        ->first();
    yes($doc !== null);
    eq(count((array) $doc), 12);
    eq($doc->product_id, null);
    eq($doc->status, "pending");
    eq($doc->content_hash, hash("sha256", $doc->content_text));
});
test("Stale UUID job does not publish", function () {
    fakeHttp(
        fn() => throw new RuntimeException("Stale job must not make HTTP"),
    );
    $doc = DB::table("ai_knowledge_documents")
        ->where("usage_guide_id", 1)
        ->first();
    $job = new IndexAiDocument(
        $doc->id,
        "00000000-0000-4000-8000-000000000000",
        $doc->content_hash,
        AiDocumentBuilder::fingerprint(),
    );
    $job->handle(
        app(GeminiClient::class),
        app(QdrantClient::class),
        app(AiDocumentBuilder::class),
    );
    eq(
        DB::table("ai_knowledge_documents")
            ->where("id", $doc->id)
            ->value("status"),
        "pending",
    );
});
test(
    "CAS prevents in-flight old job overwriting newer token and deletes old point",
    function () {
        $doc = DB::table("ai_knowledge_documents")
            ->where("usage_guide_id", 1)
            ->first();
        $uuid = (string) Str::uuid();
        $deleted = [];
        fakeHttp(function ($r) use ($doc, $uuid, &$deleted) {
            if (str_contains($r->url(), ":embedContent")) {
                return Http::response(["embedding" => ["values" => [1, 2, 3]]]);
            }
            if (str_contains($r->url(), "/points/delete")) {
                $deleted = $r["points"];
                return Http::response(["result" => ["status" => "completed"]]);
            }
            DB::table("ai_knowledge_documents")
                ->where("id", $doc->id)
                ->update(["vector_point_id" => $uuid, "status" => "pending"]);
            return Http::response(["result" => ["status" => "completed"]]);
        });
        $job = new IndexAiDocument(
            $doc->id,
            $doc->vector_point_id,
            $doc->content_hash,
            AiDocumentBuilder::fingerprint(),
        );
        $job->handle(
            app(GeminiClient::class),
            app(QdrantClient::class),
            app(AiDocumentBuilder::class),
        );
        eq(
            DB::table("ai_knowledge_documents")
                ->where("id", $doc->id)
                ->value("vector_point_id"),
            $uuid,
        );
        eq(
            DB::table("ai_knowledge_documents")
                ->where("id", $doc->id)
                ->value("status"),
            "pending",
        );
        eq($deleted, [$doc->vector_point_id]);
    },
);
test("Embedding validates dimensions and normalizes", function () {
    fakeHttp(fn() => Http::response(["embedding" => ["values" => [3, 4, 0]]]));
    $v = app(GeminiClient::class)->embed("fixture");
    eq(count($v), 3);
    eq($v[0], 0.6);
    eq($v[1], 0.8);
    fakeHttp(fn() => Http::response(["embedding" => ["values" => [1, 2]]]));
    fault(
        fn() => app(GeminiClient::class)->embed("fixture"),
        "service_unavailable",
    );
});
test(
    "Vector source must be ready, current UUID, hash, and active",
    function () {
        $doc = DB::table("ai_knowledge_documents")
            ->where("usage_guide_id", 1)
            ->first();
        fakeHttp(function ($r) use ($doc) {
            if (str_contains($r->url(), ":embedContent")) {
                return Http::response(["embedding" => ["values" => [1, 2, 3]]]);
            }
            return Http::response([
                "result" => [
                    "points" => [
                        [
                            "id" => $doc->vector_point_id,
                            "payload" => [
                                "hash" => $doc->content_hash,
                                "fingerprint" => AiDocumentBuilder::fingerprint(),
                            ],
                        ],
                    ],
                ],
            ]);
        });
        $r = app(AiRetrieval::class);
        eq($r->packages("fixture"), []);
        DB::table("ai_knowledge_documents")
            ->where("id", $doc->id)
            ->update(["status" => "ready", "indexed_at" => now()]);
        yes(in_array(1, $r->packages("fixture"), true));
        DB::table("product_usage_guides")
            ->where("id", 1)
            ->update(["is_active" => false]);
        eq($r->packages("fixture"), []);
        DB::table("product_usage_guides")
            ->where("id", 1)
            ->update(["is_active" => true]);
    },
);
test("SQL remains usable when embedding service fails", function () use (
    $catalog,
) {
    fakeHttp(fn() => Http::response(["error" => "fixture"], 503));
    $r = $catalog->search(
        "Sản phẩm kiểm thử A",
        [],
        "semantic",
        app(AiRetrieval::class),
    );
    yes(count($r["cards"]) > 0);
    eq($r["semantic_degraded"], true);
});
test("Tools deny arbitrary function and URL/SQL parameters", function () {
    $t = app(AiTools::class);
    fault(
        fn() => $t->run("run_sql", ["sql" => "select * from users"]),
        "invalid_input",
    );
    fault(
        fn() => $t->run("get_product_details", [
            "package_id" => 1,
            "url" => "https://bad.test",
        ]),
        "invalid_input",
    );
});
test(
    "Ordinal references prior ordered list, re-reads latest price",
    function () {
        $t = app(AiTools::class);
        $t->lastPackages = [2, 1];
        DB::table("product_packages")
            ->where("id", 1)
            ->update(["price" => "130000.00"]);
        $r = $t->run("get_product_details", ["ordinal" => 2]);
        eq($r["card"]["package_id"], 1);
        eq((float) $r["card"]["price"], 130000.0);
        DB::table("product_packages")
            ->where("id", 1)
            ->update(["price" => "120000.00"]);
    },
);
test("Cheaper preserves confirmed filter", function () {
    $t = app(AiTools::class);
    $t->context = ["crop_id" => 1, "purpose_id" => 1];
    $t->lastPackages = [1, 2];
    $t->lastAdvisory = true;
    $t->lastQuery = "";
    $r = $t->run("search_products", [
        "query" => "rẻ hơn",
        "advisory" => true,
        "mode" => "cheaper",
    ]);
    eq(array_column($r["cards"], "package_id"), [2]);
});
test(
    "Gemini function loop preserves original parts, signatures and call IDs",
    function () {
        $counter = 0;
        $second = null;
        fakeHttp(function ($r) use (&$counter, &$second) {
            $counter++;
            if ($counter === 1) {
                return Http::response([
                    "candidates" => [
                        [
                            "finishReason" => "STOP",
                            "content" => [
                                "role" => "model",
                                "parts" => [
                                    [
                                        "functionCall" => [
                                            "id" => "call_fixture",
                                            "name" => "get_product_details",
                                            "args" => ["package_id" => 1],
                                        ],
                                        "thoughtSignature" =>
                                            "opaque-signature-fixture",
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]);
            }
            $second = $r->data();
            return Http::response([
                "candidates" => [
                    [
                        "finishReason" => "STOP",
                        "content" => [
                            "role" => "model",
                            "parts" => [
                                [
                                    "functionCall" => [
                                        "name" => "finish_response",
                                        "args" => [
                                            "status" => "ok",
                                            "text" =>
                                                "Bạn xem thông tin hiện tại trong thẻ nhé.",
                                            "package_ids" => [1],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);
        });
        $r = app(AiChatEngine::class)->answer(
            ["turns" => []],
            "giá sản phẩm",
            null,
        );
        eq(count($r["answer"]["cards"]), 1);
        eq(
            $second["contents"][1]["parts"][0]["thoughtSignature"],
            "opaque-signature-fixture",
        );
        eq(
            $second["contents"][2]["parts"][0]["functionResponse"]["id"],
            "call_fixture",
        );
        eq($counter, 2);
    },
);
test("Function loop is bounded", function () {
    fakeHttp(
        fn() => Http::response([
            "candidates" => [
                [
                    "finishReason" => "STOP",
                    "content" => [
                        "role" => "model",
                        "parts" => [
                            [
                                "functionCall" => [
                                    "name" => "get_product_details",
                                    "args" => ["package_id" => 1],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    );
    config(["ai.max_tool_steps" => 2]);
    fault(
        fn() => app(AiChatEngine::class)->answer([], "fixture", null),
        "service_unavailable",
    );
    config(["ai.max_tool_steps" => 8]);
});
function fixtureEngine(): AiChatEngine
{
    return new class extends AiChatEngine {
        public int $calls = 0;
        public function __construct() {}
        public function answer(
            array $state,
            string $message,
            ?array $selected,
        ): array {
            $this->calls++;
            return [
                "answer" => [
                    "id" => (string) Str::uuid(),
                    "role" => "assistant",
                    "text" => "Phản hồi fixture",
                    "status" => "ok",
                    "cards" => [],
                    "options" => [],
                ],
                "context" => ["crop_id" => 1],
                "last_packages" => [2, 1],
                "last_query" => "fixture",
                "offered_options" => [],
                "pending_question" => null,
            ];
        }
    };
}
$engine = fixtureEngine();
$sessions = new AiChatSessions($engine);
$request = req();
$payload = [
    "chat_id" => null,
    "client_message_id" => (string) Str::uuid(),
    "message" => "fixture",
];
$first = null;
test("First message idempotency and reload", function () use (
    $sessions,
    $request,
    $payload,
    $engine,
    &$first,
) {
    $first = $sessions->send($request, $payload);
    $again = $sessions->send($request, $payload);
    eq($again, $first);
    eq($engine->calls, 1);
    eq(count($sessions->read($request, $first["chat_id"])["messages"]), 2);
});
test("Same client ID with changed text is denied", function () use (
    $sessions,
    $request,
    $payload,
    &$first,
) {
    fault(
        fn() => $sessions->send(
            $request,
            array_replace($payload, [
                "chat_id" => $first["chat_id"],
                "message" => "changed",
            ]),
        ),
        "invalid_input",
    );
});
test("Chat ID does not grant another session access", function () use (
    $sessions,
    &$first,
) {
    fault(
        fn() => $sessions->read(req("session-other"), $first["chat_id"]),
        "session_expired",
    );
});
test(
    "Guest, login, logout and different account never share owner",
    function () use ($sessions) {
        $a = $sessions->owner(req("same-session", 0));
        $b = $sessions->owner(req("same-session", 1));
        $c = $sessions->owner(req("same-session", 2));
        yes($a !== $b && $b !== $c && $a !== $c);
    },
);
test("Session lock stops concurrent processing", function () use (
    $sessions,
    $request,
    $payload,
    &$first,
) {
    $lock = $sessions->cache()->lock("ai:turn:" . $first["chat_id"], 120);
    $lock->get();
    fault(
        fn() => $sessions->send(
            $request,
            array_replace($payload, [
                "chat_id" => $first["chat_id"],
                "client_message_id" => (string) Str::uuid(),
            ]),
        ),
        "chat_busy",
    );
    $lock->release();
});
test("Expiry is explicit and new conversation separate", function () use (
    $sessions,
    $request,
    &$first,
) {
    $sessions->delete($request, $first["chat_id"]);
    fault(
        fn() => $sessions->read($request, $first["chat_id"]),
        "session_expired",
    );
    $next = $sessions->send($request, [
        "client_message_id" => (string) Str::uuid(),
        "message" => "mới",
    ]);
    yes($next["chat_id"] !== $first["chat_id"]);
});
test(
    "Multiple guides require confirmed selection before dose calculation",
    function () use ($calculator, $calcArgs, $context) {
        $g = (array) DB::table("product_usage_guides")->where("id", 1)->first();
        unset($g["id"]);
        $g["application_method"] = "Cách dùng kiểm thử khác";
        $id = DB::table("product_usage_guides")->insertGetId($g);
        fault(
            fn() => $calculator->calculate($calcArgs, $context),
            "needs_clarification",
        );
        eq(
            $calculator->calculate($calcArgs, $context + ["guide_id" => 1])[
                "amount_min"
            ],
            "50.000000",
        );
        fault(
            fn() => $calculator->calculate(
                $calcArgs,
                $context + ["guide_id" => $id],
            ),
            "no_verified_match",
        );
        DB::table("product_usage_guides")->where("id", $id)->delete();
    },
);
test(
    "Rollback does not retain unique lock and lose next committed job",
    function () {
        $before = DB::table("jobs")->count();
        app(AiIndexCoordinator::class)->touch("product", 1);
        eq(DB::table("jobs")->count(), $before + 1);
    },
);
test("Reference list is cleared when user changes crop", function () {
    $t = app(AiTools::class);
    $t->context = ["crop_id" => 1, "purpose_id" => 1];
    $t->lastPackages = [1, 2];
    $t->lastQuery = "old plant";
    $t->run("set_advisory_context", ["patch" => ["crop_id" => 2]]);
    eq($t->lastPackages, []);
    eq($t->lastQuery, "");
});
test("Rate limit applies before a second paid model request", function () {
    $engine = fixtureEngine();
    $s = new AiChatSessions($engine);
    $r = req("limit-session");
    config(["ai.per_minute" => 1]);
    $s->send($r, [
        "client_message_id" => (string) Str::uuid(),
        "message" => "1",
    ]);
    fault(
        fn() => $s->send($r, [
            "client_message_id" => (string) Str::uuid(),
            "message" => "2",
        ]),
        "rate_limited",
    );
    eq($engine->calls, 1);
    config(["ai.per_minute" => 12]);
});
test(
    "AI provider registers exactly five routes with session and permission middleware",
    function () use ($app) {
        $app->instance("files", new \Illuminate\Filesystem\Filesystem());
        $app->setBasePath(
            dirname((new ReflectionClass(AiCatalog::class))->getFileName(), 4),
        );
        (new \App\Providers\AiServiceProvider($app))->boot();
        $routes = array_values(
            array_filter(
                $app["router"]->getRoutes()->getRoutes(),
                fn($r) => str_starts_with($r->uri(), "api/v1/ai-"),
            ),
        );
        eq(count($routes), 5);
        foreach ($routes as $r) {
            yes(in_array("web", $r->middleware(), true));
        }
        $admin = array_values(
            array_filter(
                $routes,
                fn($r) => str_contains($r->uri(), "ai-knowledge"),
            ),
        );
        eq(count($admin), 2);
        yes(
            in_array(
                "permission:ai-knowledge.reindex",
                $admin[1]->middleware(),
                true,
            ),
        );
    },
);
test(
    "One-tank calculation requires explicit per-tank area basis",
    function () use ($calculator, $calcArgs, $context) {
        fault(
            fn() => $calculator->calculate(
                array_replace($calcArgs, [
                    "target" => "one_tank",
                    "water_l" => "16",
                ]),
                $context,
            ),
            "insufficient_calculation_data",
        );
        $c = $calculator->calculate(
            array_replace($calcArgs, [
                "target" => "one_tank",
                "water_l" => "16",
                "area_scope" => "one_tank",
            ]),
            $context,
        );
        eq($c["amount_min"], "50.000000");
    },
);
$failed = array_values(array_filter($results, fn($r) => !$r["passed"]));
echo json_encode(
    [
        "tests" => count($results),
        "passed" => count($results) - count($failed),
        "assertions" => $assertions,
        "failed" => $failed,
    ],
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
) . "\n";
exit($failed ? 1 : 0);