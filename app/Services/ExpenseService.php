<?php

namespace App\Services;

use App\Contracts\Repositories\ExpenseRepositoryInterface;
use App\Contracts\Services\ExpenseServiceInterface;
use App\Models\{Expense, ExpenseCategory, Order, Payment, InventoryDocument};
use App\Support\{ExpenseState, FinanceMoney, DashboardPeriod};
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ExpenseService implements ExpenseServiceInterface
{
    public function __construct(
        private ExpenseRepositoryInterface $repository,
    ) {}
    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => [$message]]);
    }
    private function fingerprint(array $data): string
    {
        ksort($data);
        return hash(
            "sha256",
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );
    }
    private function replay(object $event, string $hash): Expense
    {
        if (!hash_equals($event->payload_hash, $hash)) {
            throw new ConflictHttpException(
                "Mã yêu cầu đã được dùng cho nội dung khác. Tải lại dữ liệu trước khi gửi thao tác mới.",
            );
        }
        return $this->repository->detail((int) $event->expense_id);
    }
    public function write(
        string $action,
        ?int $id,
        array $data,
        int $actor,
    ): Expense {
        $key = $data["request_key"];
        $hash = $this->fingerprint([
            "action" => $action,
            "id" => $id,
            "actor" => $actor,
            "data" => $this->fingerprint($data),
        ]);
        try {
            return DB::transaction(function () use (
                $action,
                $id,
                $data,
                $actor,
                $key,
                $hash,
            ) {
                $event = DB::table("expense_events")
                    ->where("request_key", $key)
                    ->first();
                if ($event) {
                    return $this->replay($event, $hash);
                }
                $before = [];
                if ($action === "create") {
                    $fields = $this->fields($data);
                    $e = Expense::create([
                        ...$fields,
                        "entry_key" => "manual-expense:" . $key,
                        "created_by" => $actor,
                        "status" => "draft",
                    ]);
                } else {
                    $e = Expense::query()->lockForUpdate()->findOrFail($id);
                    // A concurrent identical request may have committed while this one waited.
                    $event = DB::table("expense_events")
                        ->where("request_key", $key)
                        ->lockForUpdate()
                        ->first();
                    if ($event) {
                        return $this->replay($event, $hash);
                    }
                    if (
                        (int) $e->lock_version !== (int) $data["lock_version"]
                    ) {
                        throw new ConflictHttpException(
                            "Khoản chi vừa được thay đổi. Đóng cửa sổ và tải lại trước khi thao tác.",
                        );
                    }
                    if (!ExpenseState::manual($e)) {
                        $this->fail(
                            "expense",
                            "Khoản chi từ kho, dòng đảo hoặc dữ liệu cũ chỉ được xem tại đây. Cần đối chiếu nghiệp vụ nguồn để sửa sai.",
                        );
                    }
                    $before = ExpenseState::snapshot($e);
                    if ($e->reversal()->exists()) {
                        $this->fail(
                            "expense",
                            "Khoản chi đã được đảo, không thể tiếp tục thay đổi.",
                        );
                    }
                    switch ($action) {
                        case "update":
                            $this->draft($e);
                            $fields = $this->fields($data);
                            // The form edits minutes. Preserve stored seconds when a date was not changed.
                            foreach (["incurred_at", "paid_at"] as $field) {
                                if (
                                    $e->{$field} &&
                                    ($data[$field] ?? null) ===
                                    $e->{$field}
                                    ->copy()
                                    ->timezone(
                                        DashboardPeriod::TIMEZONE,
                                    )
                                    ->format("Y-m-d\TH:i")
                                ) {
                                    $fields[$field] = $e->{$field}->copy();
                                }
                            }
                            $e->fill($fields);
                            break;
                        case "post":
                            $this->draft($e);
                            $this->links(ExpenseState::snapshot($e));
                            if (FinanceMoney::cents((string) $e->amount) <= 0) {
                                $this->fail(
                                    "amount",
                                    "Số tiền phải lớn hơn 0.",
                                );
                            }
                            $e->status = "posted";
                            break;
                        case "cancel":
                            $this->draft($e);
                            $e->status = "cancelled";
                            break;
                        case "payment":
                            $this->posted($e);
                            $e->paid_at = $this->date(
                                $data["paid_at"] ?? null,
                                "paid_at",
                            );
                            break;
                        case "reverse":
                            $this->posted($e);
                            $date = $this->date(
                                $data["incurred_at"],
                                "incurred_at",
                            );
                            if (
                                $date < $e->incurred_at->format("Y-m-d H:i:s")
                            ) {
                                $this->fail(
                                    "incurred_at",
                                    "Ngày đảo không được trước ngày phát sinh khoản gốc.",
                                );
                            }
                            $reversal = Expense::create([
                                "entry_key" => "expense-reversal:" . $key,
                                "category_id" => $e->category_id,
                                "order_id" => $e->order_id,
                                "payment_id" => $e->payment_id,
                                "inventory_document_id" =>
                                $e->inventory_document_id,
                                "reverses_expense_id" => $e->id,
                                "amount" => FinanceMoney::decimal(
                                    -FinanceMoney::cents((string) $e->amount),
                                ),
                                "incurred_at" => $date,
                                "paid_at" => null,
                                "status" => "posted",
                                "created_by" => $actor,
                                "description" =>
                                "Đảo CP" .
                                    str_pad(
                                        (string) $e->id,
                                        6,
                                        "0",
                                        STR_PAD_LEFT,
                                    ) .
                                    ": " .
                                    $data["reason"],
                            ]);
                            break;
                        default:
                            $this->fail("action", "Thao tác không hợp lệ.");
                    }
                    // The original remains posted when reversed; its version changes to invalidate stale forms.
                    $e->lock_version = (int) $e->lock_version + 1;
                    $e->save();
                }
                DB::table("expense_events")->insert([
                    "expense_id" => $e->id,
                    "request_key" => $key,
                    "payload_hash" => $hash,
                    "action" => $action,
                    "actor_id" => $actor,
                    "changes" => json_encode(
                        [
                            "before" => $before,
                            "after" => ExpenseState::snapshot($e),
                            "reason" => $data["reason"] ?? null,
                            "reversal_id" => $reversal->id ?? null,
                        ],
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                    ),
                    "created_at" => now(),
                ]);
                return $this->repository->detail($e->id);
            }, 3);
        } catch (QueryException $error) {
            // The unique request key is the final race guard. A rolled-back duplicate has no side effects.
            if (
                in_array((string) $error->getCode(), ["23000", "23505"], true)
            ) {
                $event = DB::table("expense_events")
                    ->where("request_key", $key)
                    ->first();
                if ($event) {
                    return $this->replay($event, $hash);
                }
            }
            throw $error;
        }
    }
    private function draft(Expense $e): void
    {
        if ($e->status !== "draft") {
            $this->fail("expense", "Chỉ sửa, hủy hoặc ghi sổ khoản chi nháp.");
        }
    }
    private function posted(Expense $e): void
    {
        if ($e->status !== "posted") {
            $this->fail("expense", "Khoản chi chưa ghi sổ.");
        }
    }
    private function date(?string $s, string $key): ?string
    {
        if ($s === null || $s === "") {
            return null;
        }
        try {
            $d = CarbonImmutable::createFromFormat(
                "!Y-m-d\TH:i",
                $s,
                DashboardPeriod::TIMEZONE,
            );
        } catch (\Throwable) {
            $this->fail($key, "Ngày giờ không hợp lệ.");
        }
        if (!$d || $d->format("Y-m-d\TH:i") !== $s || $d->isFuture()) {
            $this->fail($key, "Ngày giờ phải hợp lệ và không ở tương lai.");
        }
        return $d
            ->setTimezone(config("app.timezone", "UTC"))
            ->format("Y-m-d H:i:s");
    }
    private function fields(array $d): array
    {
        $this->links($d);
        $amount = FinanceMoney::cents($d["amount"]);
        if ($amount <= 0) {
            $this->fail("amount", "Số tiền phải lớn hơn 0.");
        }
        return [
            "category_id" => $d["category_id"],
            "order_id" => $d["order_id"] ?? null,
            "payment_id" => $d["payment_id"] ?? null,
            "inventory_document_id" => $d["inventory_document_id"] ?? null,
            "amount" => FinanceMoney::decimal($amount),
            "incurred_at" => $this->date($d["incurred_at"], "incurred_at"),
            "paid_at" => $this->date($d["paid_at"] ?? null, "paid_at"),
            "description" => trim($d["description"]),
        ];
    }
    private function links(array $d): void
    {
        $category = ExpenseCategory::query()
            ->sharedLock()
            ->find($d["category_id"]);
        if (!$category?->is_active) {
            $this->fail("category_id", "Danh mục không tồn tại hoặc đã tắt.");
        }
        $order = null;
        if (!empty($d["order_id"])) {
            $order = Order::query()->sharedLock()->find($d["order_id"]);
            if (!$order) {
                $this->fail("order_id", "Đơn hàng không tồn tại.");
            }
        }
        if (!empty($d["payment_id"])) {
            $payment = Payment::query()->sharedLock()->find($d["payment_id"]);
            if (
                !$payment ||
                !$order ||
                (int) $payment->order_id !== (int) $order->id ||
                $payment->status !== "paid"
            ) {
                $this->fail(
                    "payment_id",
                    "Chọn khoản thanh toán đã thu thuộc đúng đơn hàng.",
                );
            }
        }
        if (!empty($d["inventory_document_id"])) {
            $doc = InventoryDocument::query()
                ->sharedLock()
                ->find($d["inventory_document_id"]);
            if (
                !$doc ||
                $doc->status !== "posted" ||
                !in_array(
                    $doc->document_type,
                    ["supplier_receipt", "sale_issue"],
                    true,
                )
            ) {
                $this->fail(
                    "inventory_document_id",
                    "Chỉ liên kết phiếu nhập hoặc xuất bán đã ghi sổ. Chi phí giảm tồn được tạo từ nghiệp vụ kho.",
                );
            }
            if (
                !empty($d["payment_id"]) ||
                ($order && (int) $doc->order_id !== (int) $order->id)
            ) {
                $this->fail(
                    "inventory_document_id",
                    "Chứng từ kho không khớp liên kết đã chọn.",
                );
            }
        }
    }
}
