<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ExpenseCategoryController extends Controller
{
    private function row(ExpenseCategory $c): array
    {
        return [
            "id" => $c->id,
            "code" => $c->code,
            "name" => $c->name,
            "is_active" => (bool) $c->is_active,
            "lock_version" => (int) $c->lock_version,
            "expenses_count" => (int) ($c->expenses_count ?? 0),
        ];
    }
    public function index(Request $r)
    {
        $d = $r->validate([
            "search" => ["nullable", "string", "max:255"],
            "is_active" => ["nullable", "boolean"],
            "page" => ["sometimes", "integer", "min:1"],
            "per_page" => ["sometimes", "integer", "between:1,100"],
        ]);
        $q = ExpenseCategory::query()->withCount("expenses");
        if (isset($d["is_active"])) {
            $q->where("is_active", $d["is_active"]);
        }
        if (!empty($d["search"])) {
            $q->where(
                fn($x) => $x
                    ->where("name", "like", "%" . $d["search"] . "%")
                    ->orWhere("code", "like", "%" . $d["search"] . "%"),
            );
        }
        $p = $q->orderBy("code")->paginate($d["per_page"] ?? 20);
        return response()->json([
            "data" => $p->getCollection()->map(fn($c) => $this->row($c)),
            "meta" => [
                "current_page" => $p->currentPage(),
                "last_page" => $p->lastPage(),
                "total" => $p->total(),
                "per_page" => $p->perPage(),
            ],
        ]);
    }
    public function store(Request $r)
    {
        $r->merge(["code" => strtoupper(trim((string) $r->input("code")))]);
        $d = $r->validate([
            "code" => [
                "required",
                "string",
                "max:50",
                'regex:/^[A-Z0-9_]+$/D',
                Rule::unique("expense_categories", "code"),
            ],
            "name" => ["required", "string", "max:255"],
            "is_active" => ["required", "boolean"],
        ]);
        try {
            $c = ExpenseCategory::create($d);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                "code" => ["Mã danh mục đã tồn tại."],
            ]);
        }
        return response()->json(["data" => $this->row($c)], 201);
    }
    public function update(Request $r, int $category)
    {
        $d = $r->validate([
            "name" => ["required", "string", "max:255"],
            "is_active" => ["required", "boolean"],
            "lock_version" => ["required", "integer", "min:0"],
        ]);
        $c = DB::transaction(function () use ($d, $category) {
            $c = ExpenseCategory::query()
                ->lockForUpdate()
                ->findOrFail($category);
            if ((int) $c->lock_version !== (int) $d["lock_version"]) {
                throw new ConflictHttpException(
                    "Danh mục vừa thay đổi. Tải lại trước khi sửa.",
                );
            }
            $c->fill([
                "name" => trim($d["name"]),
                "is_active" => $d["is_active"],
            ]);
            $c->lock_version++;
            $c->save();
            return $c->loadCount("expenses");
        }, 3);
        return response()->json(["data" => $this->row($c)]);
    }
}
