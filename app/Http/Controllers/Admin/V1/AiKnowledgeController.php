<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiIndexCoordinator;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;

class AiKnowledgeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            "status" => "nullable|in:pending,indexing,ready,failed",
            "search" => "nullable|string|max:200",
            "page" => "nullable|integer|min:1",
        ]);
        $query = DB::table("ai_knowledge_documents as d")
            ->leftJoin("products as p", "p.id", "=", "d.product_id")
            ->leftJoin(
                "product_usage_guides as g",
                "g.id",
                "=",
                "d.usage_guide_id",
            )
            ->leftJoin("product_variants as v", "v.id", "=", "g.variant_id")
            ->leftJoin("products as gp", "gp.id", "=", "v.product_id")
            ->select(
                "d.id",
                "d.product_id",
                "d.usage_guide_id",
                "d.chunk_index",
                "d.status",
                "d.indexed_at",
                "d.last_error",
                "d.updated_at",
                "p.product_name",
                "gp.product_name as guide_product_name",
                "v.variant_name",
            );
        if (!empty($data["status"])) {
            $query->where("d.status", $data["status"]);
        }
        if (!empty($data["search"])) {
            $query->where(
                fn($q) => $q
                    ->where(
                        "p.product_name",
                        "like",
                        "%" . $data["search"] . "%",
                    )
                    ->orWhere(
                        "gp.product_name",
                        "like",
                        "%" . $data["search"] . "%",
                    ),
            );
        }
        $page = $query
            ->orderByDesc("d.updated_at")
            ->orderByDesc("d.id")
            ->paginate(15);
        $counts = DB::table("ai_knowledge_documents")
            ->selectRaw("status, COUNT(*) AS total")
            ->groupBy("status")
            ->pluck("total", "status");
        return response()->json([
            "data" => $page->items(),
            "meta" => [
                "current_page" => $page->currentPage(),
                "last_page" => $page->lastPage(),
                "total" => $page->total(),
            ],
            "counts" => $counts,
        ]);
    }
    public function reindex(
        Request $request,
        AiIndexCoordinator $index,
    ): JsonResponse {
        $data = $request->validate([
            "kind" => "required|in:product,guide",
            "source_id" => "required|integer|min:1",
        ]);
        $table =
            $data["kind"] === "product" ? "products" : "product_usage_guides";
        abort_unless(
            DB::table($table)->where("id", $data["source_id"])->exists(),
            404,
        );
        $index->touch($data["kind"], (int) $data["source_id"]);
        return response()->json(
            ["message" => "Đã đưa nguồn vào hàng đợi đồng bộ."],
            202,
        );
    }
}