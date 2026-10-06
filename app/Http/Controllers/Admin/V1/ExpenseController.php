<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Contracts\Repositories\ExpenseRepositoryInterface;
use App\Contracts\Services\ExpenseServiceInterface;
use App\Http\Requests\Admin\Finance\ExpenseWriteRequest;
use App\Http\Resources\ExpenseResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    public function __construct(
        private ExpenseRepositoryInterface $repository,
        private ExpenseServiceInterface $service,
    ) {}
    public function index(Request $r)
    {
        $f = $r->validate([
            "date_from" => [
                "nullable",
                "required_with:date_to",
                "date_format:Y-m-d",
            ],
            "date_to" => [
                "nullable",
                "required_with:date_from",
                "date_format:Y-m-d",
            ],
            "search" => ["nullable", "string", "max:255"],
            "category_id" => ["nullable", "integer", "min:1"],
            "order_id" => ["nullable", "integer", "min:1"],
            "status" => [
                "nullable",
                Rule::in(["draft", "posted", "cancelled"]),
            ],
            "source" => [
                "nullable",
                Rule::in(["manual", "inventory", "reversal"]),
            ],
            "payment_state" => ["nullable", Rule::in(["paid", "unpaid"])],
            "page" => ["sometimes", "integer", "min:1"],
            "per_page" => ["sometimes", "integer", "between:1,50"],
        ]);
        return ExpenseResource::collection(
            $this->repository
                ->query($f)
                ->orderByDesc("incurred_at")
                ->orderByDesc("id")
                ->paginate($f["per_page"] ?? 20),
        );
    }
    public function show(int $expense)
    {
        $e = $this->repository->detail($expense);
        $events = DB::table("expense_events as ev")
            ->leftJoin("users as u", "u.id", "=", "ev.actor_id")
            ->where("ev.expense_id", $expense)
            ->orderByDesc("ev.id")
            ->get([
                "ev.action",
                "ev.changes",
                "ev.created_at",
                "u.name as actor_name",
            ])
            ->map(function ($ev) {
                $ev->changes = json_decode($ev->changes, true);
                foreach (["before", "after"] as $side) {
                    foreach (["incurred_at", "paid_at"] as $key) {
                        if (!empty($ev->changes[$side][$key])) {
                            $ev->changes[$side][$key] = \Carbon\CarbonImmutable::parse(
                                $ev->changes[$side][$key],
                                config("app.timezone", "UTC"),
                            )->toIso8601String();
                        }
                    };
                }
                $ev->created_at = \Carbon\CarbonImmutable::parse(
                    $ev->created_at,
                    config("app.timezone", "UTC"),
                )->toIso8601String();
                return $ev;
            });
        return (new ExpenseResource($e))->additional(["events" => $events]);
    }
    public function write(ExpenseWriteRequest $r)
    {
        $expense = $r->route("expense");
        $expense = $expense === null ? null : (int) $expense;
        $action = $r->route()->defaults["expense_action"];
        $data = $r->validated();
        if (
            in_array($action, ["create", "update"], true) &&
            !$r->user()->can("expense.pay")
        ) {
            $previous = $expense
                ? $this->repository
                ->detail($expense)
                ->paid_at?->copy()
                ->timezone(\App\Support\DashboardPeriod::TIMEZONE)
                ->format("Y-m-d\TH:i")
                : null;
            abort_if(
                ($data["paid_at"] ?? null) !== $previous,
                403,
                "Bạn không có quyền cập nhật thông tin trả tiền.",
            );
        }
        if (in_array($action, ["create", "update"], true)) {
            abort_if(
                (!empty($data["order_id"]) || !empty($data["payment_id"])) &&
                    !$r->user()->can("order.view"),
                403,
            );
            abort_if(
                !empty($data["inventory_document_id"]) &&
                    !$r->user()->can("inventory.view"),
                403,
            );
        }
        return (new ExpenseResource(
            $this->service->write(
                $action,
                $expense,
                $data,
                (int) $r->user()->id,
            ),
        ))->additional(["message" => "Đã lưu thao tác chi phí."]);
    }
}
