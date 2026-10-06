<?php

namespace Tests\Feature;

use App\Models\{Expense, ExpenseCategory, Order, OrderItem, Payment};
use App\Services\{ExpenseService, ProfitReportService};
use App\Repositories\{ExpenseRepository, ProfitReportRepository};
use App\Support\{DashboardPeriod, FinanceMoney};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema, Gate};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

// Dedicated in-memory SQLite connection. Does NOT migrate/reset the application's database.
// MySQL lock contention still needs a separate integration run on a test MySQL database.
class FinanceModuleTest extends TestCase
{
    private ExpenseService $service;
    private array $permissions = [];
    protected function setUp(): void
    {
        parent::setUp();
        config([
            "app.timezone" => "UTC",
            "database.default" => "finance_test",
            "database.connections.finance_test" => [
                "driver" => "sqlite",
                "database" => ":memory:",
                "prefix" => "",
                "foreign_key_constraints" => true,
            ],
        ]);
        DB::purge("finance_test");
        date_default_timezone_set("UTC");
        \Carbon\Carbon::setTestNow("2026-10-06 06:00:00");
        \Carbon\CarbonImmutable::setTestNow("2026-10-06 06:00:00");
        Schema::create("users", function (Blueprint $t) {
            $t->id();
            $t->string("name");
            $t->string("email")->nullable();
            $t->timestamps();
        });
        DB::table("users")->insert(["id" => 1, "name" => "Người kiểm thử"]);
        Schema::create("expense_categories", function (Blueprint $t) {
            $t->id();
            $t->string("code")->unique();
            $t->string("name");
            $t->boolean("is_active")->default(true);
            $t->timestamps();
        });
        Schema::create("orders", function (Blueprint $t) {
            $t->id();
            $t->string("order_status");
            $t->string("payment_method");
            $t->string("payment_review")->nullable();
            $t->dateTime("payment_expires_at")->nullable();
            foreach (
                ["total_payment", "delivery_cost", "discount_amount"]
                as $k
            ) {
                $t->decimal($k, 18, 2);
            }
            $t->integer("total_quantity");
            $t->dateTime("completed_at")->nullable();
            $t->timestamps();
        });
        Schema::create("order_items", function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger("order_id");
            $t->integer("quantity");
            foreach (
                ["price", "discount_amount", "net_sales_amount", "cost_total"]
                as $k
            ) {
                $t->decimal($k, 18, 2)->nullable();
            }
            $t->timestamps();
        });
        Schema::create("payments", function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger("order_id");
            $t->string("status");
            $t->string("payment_method");
            $t->decimal("amount", 18, 2);
            $t->string("transaction_id")->nullable();
            $t->dateTime("paid_at")->nullable();
            $t->timestamps();
        });
        Schema::create("inventory_documents", function (Blueprint $t) {
            $t->id();
            $t->string("document_number");
            $t->string("document_type");
            $t->string("status");
            $t->unsignedBigInteger("order_id")->nullable();
            $t->timestamps();
        });
        Schema::create("expenses", function (Blueprint $t) {
            $t->id();
            $t->string("entry_key", 150)->unique();
            $t->foreignId("category_id")
                ->constrained("expense_categories")
                ->restrictOnDelete();
            $t->foreignId("order_id")->nullable()->constrained("orders");
            $t->foreignId("payment_id")->nullable()->constrained("payments");
            $t->foreignId("inventory_document_id")
                ->nullable()
                ->constrained("inventory_documents");
            $t->foreignId("reverses_expense_id")
                ->nullable()
                ->unique()
                ->constrained("expenses");
            $t->decimal("amount", 18, 2);
            $t->dateTime("incurred_at");
            $t->dateTime("paid_at")->nullable();
            $t->string("status")->default("draft");
            $t->foreignId("created_by")->nullable()->constrained("users");
            $t->text("description");
            $t->timestamps();
        });
        $migration = require base_path(
            "database/migrations/2026_10_06_000001_add_expense_audit_support.php",
        );
        $migration->up();
        ExpenseCategory::create([
            "code" => "SHIPPING",
            "name" => "Cước giao hàng",
            "is_active" => true,
        ]);
        $this->service = new ExpenseService(new ExpenseRepository());
        if (
            !$this->app->providerIsLoaded(
                \App\Providers\FinanceServiceProvider::class,
            )
        ) {
            $this->app->register(\App\Providers\FinanceServiceProvider::class);
        }
        // Authentication is stubbed only; real Gate permission checks remain active.
        $this->withoutMiddleware(
            \Illuminate\Auth\Middleware\Authenticate::class,
        );
        $user = (new FinanceModuleTestUser())->forceFill([
            "id" => 1,
            "name" => "Người kiểm thử",
        ]);
        $this->actingAs($user, "web");
        Gate::before(
            fn($user, $ability) => in_array($ability, $this->permissions, true),
        );
    }
    protected function tearDown(): void
    {
        \Carbon\Carbon::setTestNow();
        \Carbon\CarbonImmutable::setTestNow();
        DB::purge("finance_test");
        parent::tearDown();
    }
    private function body(array $overrides = []): array
    {
        return array_merge(
            [
                "request_key" => (string) Str::uuid(),
                "category_id" => 1,
                "amount" => "25000.50",
                "description" => "Cước thực tế",
                "incurred_at" => "2026-10-04T09:30",
                "paid_at" => null,
                "order_id" => null,
                "payment_id" => null,
                "inventory_document_id" => null,
            ],
            $overrides,
        );
    }
    private function create(array $overrides = []): Expense
    {
        return $this->service->write(
            "create",
            null,
            $this->body($overrides),
            1,
        );
    }
    private function action(string $a, Expense $e, array $d = []): Expense
    {
        return $this->service->write(
            $a,
            $e->id,
            array_merge(
                [
                    "request_key" => (string) Str::uuid(),
                    "lock_version" => (int) $e->lock_version,
                ],
                $d,
            ),
            1,
        );
    }
    private function report(
        string $from = "2026-10-04",
        string $to = "2026-10-04",
    ): array {
        return (new ProfitReportService(
            new ProfitReportRepository(new ExpenseRepository()),
        ))->report(new DashboardPeriod($from, $to));
    }
    private function order(
        ?string $cost = "60000.00",
        array $overrides = [],
    ): Order {
        $o = Order::create(
            array_merge(
                [
                    "order_status" => "completed",
                    "payment_method" => "COD",
                    "total_payment" => "120000.00",
                    "delivery_cost" => "30000.00",
                    "discount_amount" => "10000.00",
                    "total_quantity" => 2,
                    "completed_at" => "2026-10-03 17:30:00",
                ],
                $overrides,
            ),
        );
        OrderItem::create([
            "order_id" => $o->id,
            "quantity" => 2,
            "price" => "50000.00",
            "discount_amount" => "10000.00",
            "net_sales_amount" => "90000.00",
            "cost_total" => $cost,
        ]);
        return $o;
    }
    public function test_create_replay_and_changed_payload_conflict(): void
    {
        $d = $this->body();
        $a = $this->service->write("create", null, $d, 1);
        $b = $this->service->write("create", null, $d, 1);
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Expense::count());
        $this->assertSame(1, DB::table("expense_events")->count());
        $this->expectException(ConflictHttpException::class);
        $d["amount"] = "99.00";
        $this->service->write("create", null, $d, 1);
    }
    public function test_draft_post_payment_and_cross_period_reversal(): void
    {
        $e = $this->create();
        $this->assertSame("0.00", $this->report()["summary"]["expenses"]);
        $e = $this->action("post", $e);
        $this->assertSame("25000.50", $this->report()["summary"]["expenses"]);
        $e = $this->action("payment", $e, [
            "paid_at" => "2026-10-05T10:00",
            "reason" => "Đã trả đủ",
        ]);
        $this->assertNotNull($e->paid_at);
        $this->assertSame("25000.50", $this->report()["summary"]["expenses"]);
        $e = $this->action("reverse", $e, [
            "incurred_at" => "2026-10-05T11:00",
            "reason" => "Sai số tiền",
        ]);
        $this->assertSame("posted", $e->status);
        $this->assertSame("-25000.50", $e->reversal->amount);
        $this->assertNull($e->reversal->paid_at);
        $this->assertSame("25000.50", $this->report()["summary"]["expenses"]);
        $this->assertSame(
            "-25000.50",
            $this->report("2026-10-05", "2026-10-05")["summary"]["expenses"],
        );
        $this->assertSame(
            "0.00",
            $this->report("2026-10-04", "2026-10-05")["summary"]["expenses"],
        );
        $this->assertSame(4, DB::table("expense_events")->count());
    }
    public function test_duplicate_post_and_reverse_do_not_add_events_or_rows(): void
    {
        $e = $this->create();
        $d = ["request_key" => (string) Str::uuid(), "lock_version" => 0];
        $e = $this->service->write("post", $e->id, $d, 1);
        $this->service->write("post", $e->id, $d, 1);
        $d = [
            "request_key" => (string) Str::uuid(),
            "lock_version" => 1,
            "incurred_at" => "2026-10-05T09:00",
            "reason" => "Sửa sai",
        ];
        $this->service->write("reverse", $e->id, $d, 1);
        $this->service->write("reverse", $e->id, $d, 1);
        $this->assertSame(2, Expense::count());
        $this->assertSame(3, DB::table("expense_events")->count());
    }
    public function test_stale_editor_gets_conflict(): void
    {
        $e = $this->create();
        $this->action("post", $e);
        $this->expectException(ConflictHttpException::class);
        $this->service->write(
            "update",
            $e->id,
            $this->body(["lock_version" => 0]),
            1,
        );
    }
    public function test_posted_content_is_immutable(): void
    {
        $e = $this->action("post", $this->create());
        $this->expectException(ValidationException::class);
        $this->service->write(
            "update",
            $e->id,
            $this->body(["lock_version" => 1, "amount" => "123.00"]),
            1,
        );
    }
    public function test_cancel_draft_is_excluded(): void
    {
        $e = $this->action("cancel", $this->create(), [
            "reason" => "Nhập nhầm",
        ]);
        $this->assertSame("cancelled", $e->status);
        $this->assertSame("0.00", $this->report()["summary"]["expenses"]);
        $this->expectException(ValidationException::class);
        $this->action("post", $e);
    }
    public function test_inventory_expense_counts_once_and_cannot_be_manually_reversed(): void
    {
        $e = $this->create();
        $e->entry_key = "inventory-adjustment:test";
        $e->status = "posted";
        $e->save();
        $this->assertSame("25000.50", $this->report()["summary"]["expenses"]);
        $this->expectException(ValidationException::class);
        $this->action("reverse", $e, [
            "incurred_at" => "2026-10-05T09:00",
            "reason" => "Thử đảo",
        ]);
    }
    public function test_invalid_payment_link_rolls_back(): void
    {
        $o = $this->order();
        $other = $this->order();
        $p = Payment::create([
            "order_id" => $other->id,
            "status" => "paid",
            "amount" => "120000.00",
            "payment_method" => "COD",
        ]);
        try {
            $this->create(["order_id" => $o->id, "payment_id" => $p->id]);
            $this->fail("Expected validation error");
        } catch (ValidationException) {
            $this->assertSame(0, Expense::count());
        }
    }
    public function test_manual_inventory_adjustment_link_rejected(): void
    {
        $id = DB::table("inventory_documents")->insertGetId([
            "document_number" => "INV-1",
            "document_type" => "adjustment",
            "status" => "posted",
        ]);
        $this->expectException(ValidationException::class);
        $this->create(["inventory_document_id" => $id]);
    }
    public function test_disabled_category_rejected_at_post(): void
    {
        $e = $this->create();
        ExpenseCategory::whereKey(1)->update(["is_active" => false]);
        $this->expectException(ValidationException::class);
        $this->action("post", $e);
    }
    public function test_profit_formula_uses_completion_date_and_signed_posted_expenses(): void
    {
        $this->order();
        $this->order(overrides: ["completed_at" => "2026-10-04 17:00:00"]); // VN next day, excluded
        $this->action("post", $this->create());
        $r = $this->report();
        $this->assertSame(1, $r["summary"]["completed_orders"]);
        $this->assertSame("90000.00", $r["summary"]["net_sales"]);
        $this->assertSame("30000.00", $r["summary"]["gross_profit"]);
        $this->assertSame("34999.50", $r["summary"]["pretax_profit"]);
        $this->assertSame(
            "cod_uncollected",
            $r["orders"]["data"][0]["payment_state"],
        );
        $this->assertSame("34999.50", $r["daily"][0]["pretax_profit"]);
    }
    public function test_missing_cost_and_snapshot_mismatch_are_visible_not_zero(): void
    {
        $this->order(null);
        $r = $this->report();
        $this->assertNull($r["summary"]["cost_total"]);
        $this->assertNull($r["summary"]["pretax_profit"]);
        $this->assertSame(1, $r["issues"]["meta"]["total"]);
        OrderItem::query()->update([
            "cost_total" => "60000.00",
            "net_sales_amount" => "89000.00",
        ]);
        $r = $this->report();
        $this->assertNull($r["summary"]["gross_profit"]);
        $this->assertSame("60000.00", $r["summary"]["cost_total"]);
    }
    public function test_payment_retries_do_not_multiply_revenue(): void
    {
        $o = $this->order(overrides: ["payment_method" => "VNPAY"]);
        foreach (["failed", "paid", "pending"] as $status) {
            Payment::create([
                "order_id" => $o->id,
                "status" => $status,
                "amount" => "120000.00",
                "payment_method" => "VNPAY",
            ]);
        }
        $r = $this->report();
        $this->assertSame("90000.00", $r["summary"]["net_sales"]);
        $this->assertSame("paid", $r["orders"]["data"][0]["payment_state"]);
        Payment::create([
            "order_id" => $o->id,
            "status" => "paid",
            "amount" => "120000.00",
            "payment_method" => "VNPAY",
        ]);
        $r = $this->report();
        $this->assertSame(1, $r["quality"]["payment_review_orders"]);
        $this->assertSame("60000.00", $r["summary"]["pretax_profit"]);
    }
    public function test_signed_money_has_exact_precision(): void
    {
        $this->assertSame(
            "-0.50",
            FinanceMoney::decimal(FinanceMoney::cents("-0.50")),
        );
        $this->assertSame(
            "9999999999999999.99",
            FinanceMoney::decimal(FinanceMoney::cents("9999999999999999.99")),
        );
        $this->assertSame(
            0,
            FinanceMoney::add(
                FinanceMoney::cents("9999999999999999.99"),
                FinanceMoney::cents("-9999999999999999.99"),
            ),
        );
    }
    public function test_endpoint_permissions_and_create_post_dispatch(): void
    {
        $this->getJson("/api/v1/expenses")->assertForbidden();
        $this->getJson(
            "/api/v1/reports/profit?date_from=2026-10-04&date_to=2026-10-04",
        )->assertForbidden();
        $this->permissions = ["expense.view", "expense.create"];
        $r = $this->postJson("/api/v1/expenses", $this->body())
            ->assertOk()
            ->assertJsonPath("data.status", "draft");
        $id = $r->json("data.id");
        $body = ["request_key" => (string) Str::uuid(), "lock_version" => 0];
        $this->postJson(
            "/api/v1/expenses/" . $id . "/post",
            $body,
        )->assertForbidden();
        $this->permissions[] = "expense.post";
        $this->postJson("/api/v1/expenses/" . $id . "/post", $body)
            ->assertOk()
            ->assertJsonPath("data.status", "posted");
    }
    public function test_create_cannot_bypass_payment_permission(): void
    {
        $this->permissions = ["expense.view", "expense.create"];
        $this->postJson(
            "/api/v1/expenses",
            $this->body(["paid_at" => "2026-10-04T10:00"]),
        )->assertForbidden();
        $this->assertSame(0, Expense::count());
    }
    public function test_resource_disallows_cash_actions_for_inventory(): void
    {
        $e = $this->create();
        $e->entry_key = "inventory-adjustment:one";
        $e->status = "posted";
        $e->save();
        $this->permissions = ["expense.view", "expense.pay", "expense.reverse"];
        $this->getJson("/api/v1/expenses/" . $e->id)
            ->assertOk()
            ->assertJsonPath("data.can_pay", false)
            ->assertJsonPath("data.can_reverse", false)
            ->assertJsonPath("data.payment_state", "not_applicable");
    }
    public function test_csv_export_permission_and_injection_guard(): void
    {
        $this->action(
            "post",
            $this->create(["description" => '=HYPERLINK("bad")']),
        );
        $url =
            "/api/v1/reports/profit/export?date_from=2026-10-04&date_to=2026-10-04&dataset=expenses";
        $this->permissions = ["report.profit.view"];
        $this->getJson($url)->assertForbidden();
        $this->permissions[] = "report.profit.export";
        $r = $this->get($url)->assertOk();
        $this->assertStringContainsString("'=HYPERLINK", $r->streamedContent());
    }
    public function test_reversal_date_before_original_rejected(): void
    {
        $e = $this->action("post", $this->create());
        $this->expectException(ValidationException::class);
        $this->action("reverse", $e, [
            "incurred_at" => "2026-10-03T09:00",
            "reason" => "Sai ngày",
        ]);
    }
    public function test_category_update_cannot_overwrite_newer_changes(): void
    {
        $this->permissions = ["expense.view", "expense-category.manage"];
        $this->putJson("/api/v1/expense-categories/1", [
            "name" => "Vận chuyển",
            "is_active" => false,
            "lock_version" => 0,
        ])->assertOk();
        $this->putJson("/api/v1/expense-categories/1", [
            "name" => "Tên cũ",
            "is_active" => true,
            "lock_version" => 0,
        ])->assertConflict();
        $this->assertFalse(ExpenseCategory::find(1)->is_active);
    }
    public function test_editing_other_fields_preserves_payment_seconds_without_payment_permission(): void
    {
        $e = $this->create(["paid_at" => "2026-10-04T10:00"]);
        $e->paid_at = "2026-10-04 03:00:45";
        $e->save();
        $this->permissions = ["expense.view", "expense.update"];
        $this->putJson(
            "/api/v1/expenses/" . $e->id,
            $this->body([
                "lock_version" => 0,
                "paid_at" => "2026-10-04T10:00",
                "description" => "Nội dung mới",
            ]),
        )->assertOk();
        $this->assertSame(
            "2026-10-04 03:00:45",
            $e->fresh()->paid_at->format("Y-m-d H:i:s"),
        );
    }
    public function test_invalid_legacy_reversal_is_reported_instead_of_counted(): void
    {
        $e = $this->action("post", $this->create());
        $e = $this->action("reverse", $e, [
            "incurred_at" => "2026-10-05T11:00",
            "reason" => "Sửa sai",
        ]);
        Expense::whereKey($e->id)->update(["status" => "draft"]);
        $r = $this->report("2026-10-05", "2026-10-05");
        $this->assertNull($r["summary"]["expenses"]);
        $this->assertNull($r["summary"]["pretax_profit"]);
        $this->assertFalse($r["quality"]["profit_complete"]);
        $this->assertSame(1, $r["issues"]["meta"]["total"]);
    }
}
class FinanceModuleTestUser extends \Illuminate\Foundation\Auth\User
{
    protected $guarded = [];
}
