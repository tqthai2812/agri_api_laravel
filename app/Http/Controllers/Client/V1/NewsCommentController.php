<?php
namespace App\Http\Controllers\Client\V1;
use App\Http\Controllers\Controller;
use App\Models\NewsComment;
use App\Support\NewsUserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class NewsCommentController extends Controller
{
    public static function data(NewsComment $c): array
    {
        return [
            "id" => $c->id,
            "news_id" => $c->news_id,
            "parent_id" => $c->parent_id,
            "user_id" => $c->user_id,
            "author" => $c->user?->name ?? "Thành viên",
            "avatar_url" => NewsUserProfile::avatar($c->user?->avatar),
            "content" => $c->content,
            "status" => $c->status,
            // Legacy columns are not authoritative: count real reaction rows.
            "like_count" => (int) ($c->reaction_like_count ?? 0),
            "dislike_count" => (int) ($c->reaction_dislike_count ?? 0),
            "my_reaction" => $c->my_reaction ?? null,
            "created_at" => $c->created_at?->toIso8601String(),
            "replies_count" => (int) ($c->replies_count ?? 0),
            "article_title" => $c->news?->title,
        ];
    }
    public function index(Request $r, string $slug)
    {
        $n = PublicNewsController::published()
            ->where("slug", $slug)
            ->firstOrFail();
        $r->validate([
            "page" => "nullable|integer|min:1",
            "parent_id" => "nullable|integer",
        ]);
        $q = $n
            ->comments()
            ->visible()
            ->with(["user:id,name,avatar", "news:id,title"])
            ->withReactionSummary($r->user("sanctum")?->id);
        if ($r->filled("parent_id")) {
            $n->comments()
                ->visible()
                ->whereNull("parent_id")
                ->findOrFail($r->parent_id);
            $q->where("parent_id", $r->parent_id)->orderBy("id");
        } else {
            $q->whereNull("parent_id")->orderByDesc("id");
        }
        return $q
            ->withCount(["replies" => fn($q) => $q->visible()])
            ->paginate(10)
            ->through(fn($c) => self::data($c));
    }
    public function mine(Request $r, string $slug)
    {
        $n = PublicNewsController::published()
            ->where("slug", $slug)
            ->firstOrFail();
        return $n
            ->comments()
            ->where("user_id", $r->user()->id)
            ->with(["user:id,name,avatar", "news:id,title"])
            ->withReactionSummary((int) $r->user()->id)
            ->latest("id")
            ->paginate(15)
            ->through(fn($c) => self::data($c));
    }
    public function store(Request $r, string $slug)
    {
        $d = $r->validate([
            "content" => "required|string|max:3000",
            "parent_id" => "nullable|integer",
        ]);
        $c = DB::transaction(function () use ($r, $slug, $d) {
            $n = PublicNewsController::published()
                ->where("slug", $slug)
                ->lockForUpdate()
                ->firstOrFail();
            if (!empty($d["parent_id"])) {
                $n->comments()
                    ->visible()
                    ->whereNull("parent_id")
                    ->lockForUpdate()
                    ->findOrFail($d["parent_id"]);
            }
            $text = trim($d["content"]);
            if ($text === "") {
                throw ValidationException::withMessages([
                    "content" => "Nhập nội dung bình luận.",
                ]);
            }
            return $n->comments()->create([
                "user_id" => $r->user()->id,
                "parent_id" => $d["parent_id"] ?? null,
                "content" => $text,
                "status" => "active",
                "like_count" => 0,
                "dislike_count" => 0,
            ]);
        });
        return response()->json(
            [
                "data" => self::data(
                    $c->load(["user:id,name,avatar", "news:id,title"]),
                ),
                "message" => "Đã đăng bình luận.",
            ],
            201,
        );
    }
    public function update(Request $r, NewsComment $comment)
    {
        $d = $r->validate(["content" => "required|string|max:3000"]);
        DB::transaction(function () use ($r, $comment, $d) {
            $c = NewsComment::whereKey($comment->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless((int) $c->user_id === (int) $r->user()->id, 403);
            PublicNewsController::published()->findOrFail($c->news_id);
            $text = trim($d["content"]);
            if ($text === "") {
                throw ValidationException::withMessages([
                    "content" => "Nhập nội dung bình luận.",
                ]);
            }
            // Editing must not undo the administrator's decision to hide.
            $c->update(["content" => $text]);
        });
        return ["message" => "Đã cập nhật bình luận."];
    }
    public function destroy(Request $r, NewsComment $comment)
    {
        DB::transaction(function () use ($r, $comment) {
            $c = NewsComment::whereKey($comment->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless((int) $c->user_id === (int) $r->user()->id, 403);
            $c->delete();
        });
        return response()->noContent();
    }
    public function moderation(Request $r)
    {
        $r->validate([
            "status" => "nullable|in:pending,active,hidden",
            "search" => "nullable|string|max:200",
        ]);
        return NewsComment::with(["user:id,name,avatar", "news:id,title"])
            ->withReactionSummary((int) $r->user()->id)
            ->when(
                $r->filled("status"),
                fn($q) => $q->where("status", $r->status),
            )
            ->when(
                $r->filled("search"),
                fn($q) => $q->where("content", "like", "%" . $r->search . "%"),
            )
            ->latest("id")
            ->paginate(20)
            ->through(fn($c) => self::data($c));
    }
    private function requireModerator(Request $r): void
    {
        abort_unless($r->user(), 401);
        abort_unless($r->user()->can("article.update"), 403);
    }
    public function hidden(Request $r, string $slug)
    {
        $this->requireModerator($r);
        $r->validate(["page" => "nullable|integer|min:1"]);
        $n = PublicNewsController::published()
            ->where("slug", $slug)
            ->firstOrFail();
        return $n
            ->comments()
            ->where(function ($q) use ($n) {
                $q->where("status", "hidden")->orWhere(function ($q) use ($n) {
                    $q->where("status", "active")->whereHas(
                        "parent",
                        fn($p) => $p
                            ->where("news_id", $n->id)
                            ->whereNull("parent_id")
                            ->where("status", "hidden"),
                    );
                });
            })
            ->with([
                "user:id,name,avatar",
                "parent" => fn($q) => $q
                    ->select("id", "news_id", "parent_id", "status", "content")
                    ->where("news_id", $n->id)
                    ->whereNull("parent_id"),
            ])
            ->latest("id")
            ->paginate(15, ["*"], "page", $r->integer("page", 1))
            ->through(function ($c) {
                $parent = $c->parent;
                $blocked = $c->parent_id && $parent?->status !== "active";
                $restoreId = $blocked
                    ? ($parent?->status === "hidden"
                        ? $parent->id
                        : null)
                    : ($c->status === "hidden"
                        ? $c->id
                        : null);
                return [
                    "id" => $c->id,
                    "parent_id" => $c->parent_id,
                    "author" => $c->user?->name ?? "Thành viên",
                    "avatar_url" => NewsUserProfile::avatar($c->user?->avatar),
                    "content" => $c->content,
                    "status" => $c->status,
                    "created_at" => $c->created_at?->toIso8601String(),
                    "hidden_by_parent" => (bool) $blocked,
                    "parent_excerpt" => $parent
                        ? \Illuminate\Support\Str::limit($parent->content, 180)
                        : null,
                    "restore_id" => $restoreId,
                ];
            });
    }
    public function restoreHidden(
        Request $r,
        string $slug,
        NewsComment $comment,
    ) {
        $this->requireModerator($r);
        return DB::transaction(function () use ($slug, $comment) {
            // Same lock order as posting comments and reacting: article, root, reply.
            $n = PublicNewsController::published()
                ->where("slug", $slug)
                ->lockForUpdate()
                ->firstOrFail();
            $target = $n->comments()->findOrFail($comment->id);
            $root = $n
                ->comments()
                ->whereNull("parent_id")
                ->whereKey($target->parent_id ?: $target->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($target->parent_id) {
                abort_unless(
                    $root->status === "active",
                    409,
                    "Hãy hiển thị lại bình luận gốc trước.",
                );
                $target = $n
                    ->comments()
                    ->where("parent_id", $root->id)
                    ->whereKey($target->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            } else {
                $target = $root;
            }
            abort_unless(
                in_array($target->status, ["active", "hidden"], true),
                409,
                "Bình luận này không ở trạng thái có thể hiển thị lại.",
            );
            if ($target->status === "hidden") {
                $target->update(["status" => "active"]);
            }
            return ["data" => ["id" => $target->id, "status" => "active"]];
        }, 3);
    }
    public function moderate(Request $r, NewsComment $comment)
    {
        $d = $r->validate(["status" => "required|in:active,hidden"]);
        DB::transaction(function () use ($comment, $d) {
            $c = NewsComment::whereKey($comment->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($d["status"] === "active" && $c->parent_id) {
                NewsComment::where("news_id", $c->news_id)
                    ->whereNull("parent_id")
                    ->where("status", "active")
                    ->findOrFail($c->parent_id);
            }
            $c->update($d);
        });
        return ["message" => "Đã cập nhật trạng thái."];
    }
    public function reply(Request $r, NewsComment $comment)
    {
        $d = $r->validate(["content" => "required|string|max:3000"]);
        if (trim($d["content"]) === "") {
            throw ValidationException::withMessages([
                "content" => "Nhập nội dung trả lời.",
            ]);
        }
        $c = DB::transaction(function () use ($comment, $r, $d) {
            $root = NewsComment::whereKey($comment->parent_id ?: $comment->id)
                ->whereNull("parent_id")
                ->where("status", "active")
                ->lockForUpdate()
                ->firstOrFail();
            PublicNewsController::published()->findOrFail($root->news_id);
            return NewsComment::create([
                "news_id" => $root->news_id,
                "parent_id" => $root->id,
                "user_id" => $r->user()->id,
                "content" => trim($d["content"]),
                "status" => "active",
                "like_count" => 0,
                "dislike_count" => 0,
            ]);
        });
        return response()->json(
            [
                "data" => self::data(
                    $c->load(["user:id,name,avatar", "news:id,title"]),
                ),
            ],
            201,
        );
    }
}