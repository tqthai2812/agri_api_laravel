<?php
namespace App\Http\Controllers\Client\V1;
use App\Http\Controllers\Controller;
use App\Models\News;
use App\Models\NewsComment;
use App\Models\Tag;
use App\Support\NewsHtml;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
class PublicNewsController extends Controller
{
    public static function published()
    {
        return News::where("is_draft", false)
            ->where("is_published", true)
            ->where(
                fn($q) => $q
                    ->whereNull("published_at")
                    ->orWhere("published_at", "<=", now()),
            );
    }
    public static function find(string $slug): News
    {
        return self::published()->where("slug", $slug)->firstOrFail();
    }
    public function index(Request $r)
    {
        $d = $r->validate([
            "search" => "nullable|string|max:200",
            "tag" => "nullable|string|max:80",
            "sort" => "nullable|in:latest,popular,comments",
            "page" => "nullable|integer|min:1",
            "per_page" => "nullable|integer|min:1|max:24",
        ]);
        $q = self::published()
            ->with(["user:id,name", "tags:id,tag_name"])
            ->when(
                $r->filled("search"),
                fn($q) => $q->where(
                    fn($q) => $q
                        ->where("title", "like", "%" . $d["search"] . "%")
                        ->orWhere("subtitle", "like", "%" . $d["search"] . "%"),
                ),
            )
            ->when(
                $r->filled("tag"),
                fn($q) => $q->whereHas(
                    "tags",
                    fn($q) => $q->where("tag_name", $d["tag"]),
                ),
            )
            ->withCount([
                "comments as comments_count" => fn($q) => $q->visible(),
            ]);
        match ($d["sort"] ?? "latest") {
            "popular" => $q->orderByDesc("views"),
            "comments" => $q->orderByDesc("comments_count"),
            default => $q->orderByDesc("published_at"),
        };
        return $q
            ->orderByDesc("id")
            ->paginate($d["per_page"] ?? 9)
            ->through(fn($n) => self::data($n));
    }
    public function topics()
    {
        return [
            "data" => Tag::whereHas(
                "news",
                fn($q) => $q
                    ->where("is_draft", false)
                    ->where("is_published", true)
                    ->where(
                        fn($q) => $q
                            ->whereNull("published_at")
                            ->orWhere("published_at", "<=", now()),
                    ),
            )
                ->orderBy("tag_name")
                ->get(["id", "tag_name"]),
        ];
    }
    public function show(string $slug)
    {
        $n = self::find($slug);
        $n->increment("views");
        $n->load(["user:id,name", "tags", "images"]);
        $n->loadCount([
            "comments as comments_count" => fn($q) => $q->visible(),
        ]);
        $related = self::published()
            ->whereKeyNot($n->id)
            ->whereHas(
                "tags",
                fn($q) => $q->whereIn("tags.id", $n->tags->pluck("id")),
            )
            ->with(["user:id,name", "tags"])
            ->latest("published_at")
            ->limit(3)
            ->get();
        return [
            "data" => self::data($n, true),
            "related" => $related->map(fn($n) => self::data($n)),
        ];
    }
    public static function data(News $n, bool $full = false): array
    {
        $html = NewsHtml::clean($n->content);
        $text = NewsHtml::text($html);
        $d = [
            "id" => $n->id,
            "title" => $n->title,
            "subtitle" => $n->subtitle,
            "excerpt" => Str::limit($text, 160),
            "slug" => $n->slug,
            "title_image_url" => NewsHtml::url($n->title_image_url),
            "author" => $n->user
                ? ["id" => $n->user->id, "name" => $n->user->name]
                : null,
            "tags" => $n->tags,
            "published_at" => $n->published_at?->toIso8601String(),
            "views" => (int) $n->views,
            "comments_count" => (int) ($n->comments_count ?? 0),
            "reading_minutes" => max(
                1,
                (int) ceil(
                    count(
                        preg_split("/\s+/u", $text, -1, PREG_SPLIT_NO_EMPTY),
                    ) / 200,
                ),
            ),
        ];
        if ($full) {
            $d += [
                "content" => $html,
                "meta_title" => $n->meta_title,
                "meta_description" => $n->meta_description,
                "images" => $n->images->map(
                    fn($i) => [
                        "id" => $i->id,
                        "image_url" => NewsHtml::url($i->image_url),
                    ],
                ),
            ];
        }
        return $d;
    }
}