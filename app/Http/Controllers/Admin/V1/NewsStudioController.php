<?php
namespace App\Http\Controllers\Admin\V1;
use App\Http\Controllers\Controller;
use App\Models\News;
use App\Models\NewsComment;
use App\Models\Tag;
use App\Support\NewsHtml;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
class NewsStudioController extends Controller
{
    public function index(Request $r)
    {
        $r->validate([
            "search" => "nullable|string|max:200",
            "status" => "nullable|in:draft,published,scheduled,hidden",
            "page" => "nullable|integer|min:1",
        ]);
        $q = News::with("user:id,name")->when(
            $r->filled("search"),
            fn($q) => $q->where(
                "title",
                "like",
                "%" . $r->string("search") . "%",
            ),
        );
        match ($r->status) {
            "draft" => $q->where("is_draft", true),
            "hidden" => $q
                ->where("is_draft", false)
                ->where("is_published", false),
            "published" => $q
                ->where("is_draft", false)
                ->where("is_published", true)
                ->where(
                    fn($q) => $q
                        ->whereNull("published_at")
                        ->orWhere("published_at", "<=", now()),
                ),
            "scheduled" => $q
                ->where("is_draft", false)
                ->where("is_published", true)
                ->where("published_at", ">", now()),
            default => null,
        };
        return $q
            ->orderByDesc("id")
            ->paginate(15)
            ->through(fn($n) => $this->data($n, false));
    }
    public function show(News $news)
    {
        return ["data" => $this->data($news)];
    }
    public function store(Request $r)
    {
        return $this->save($r, new News());
    }
    public function update(Request $r, News $news)
    {
        return $this->save($r, $news);
    }
    private function save(Request $r, News $news)
    {
        if ($r->filled("slug")) {
            $r->merge(["slug" => Str::slug($r->input("slug"))]);
        }
        $d = $r->validate([
            "title" => "required|string|max:255",
            "subtitle" => "nullable|string|max:255",
            "content" => "nullable|string|max:200000",
            "slug" => [
                "nullable",
                "string",
                "max:255",
                Rule::unique("news")->ignore($news->id),
            ],
            "status" => "required|in:draft,published,hidden",
            "published_at" => "nullable|date",
            "meta_title" => "nullable|string|max:255",
            "meta_description" => "nullable|string|max:500",
            "tags" => "present|array|max:15",
            "tags.*" => "required|string|max:80",
            "cover_id" => "nullable|integer",
        ]);
        if (!empty($d["published_at"])) {
            $d["published_at"] = \Illuminate\Support\Carbon::parse(
                $d["published_at"],
            )->setTimezone(config("app.timezone"));
        }
        $created = !$news->exists;
        $html = NewsHtml::clean($d["content"] ?? "");
        if (
            $d["status"] === "published" &&
            mb_strlen(NewsHtml::text($html)) < 20
        ) {
            throw ValidationException::withMessages([
                "content" => "Bài xuất bản cần ít nhất 20 ký tự nội dung.",
            ]);
        }
        if ($created && !empty($d["cover_id"])) {
            throw ValidationException::withMessages([
                "cover_id" => "Lưu bản nháp trước khi chọn ảnh bìa.",
            ]);
        }
        DB::transaction(function () use ($r, $d, $html, &$news, $created) {
            if (!$created) {
                $news = News::whereKey($news->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }
            $cover =
                isset($d["cover_id"]) && (int) $d["cover_id"] === 0
                    ? $news->title_image_url
                    : null;
            if (!empty($d["cover_id"])) {
                $cover = $news
                    ->images()
                    ->whereKey($d["cover_id"])
                    ->firstOrFail()->image_url;
            }
            // All embedded images must belong to this article. No remote/base64 media.
            preg_match_all('/<img\b[^>]*\bsrc="([^"]+)"/i', $html, $matches);
            $allowed = $created
                ? []
                : $news
                    ->images()
                    ->pluck("image_url")
                    ->map(fn($p) => NewsHtml::url($p))
                    ->all();
            foreach ($matches[1] as $src) {
                if (
                    !in_array(
                        html_entity_decode($src, ENT_QUOTES),
                        $allowed,
                        true,
                    )
                ) {
                    throw ValidationException::withMessages([
                        "content" =>
                            "Ảnh trong bài phải được tải lên thư viện ảnh của bài này.",
                    ]);
                }
            }
            $slug =
                Str::slug($d["slug"] ?? "") ?:
                ($news->slug ?:
                substr(Str::slug($d["title"]), 0, 240) .
                    "-" .
                    Str::lower(Str::random(8)));
            $news->fill([
                "title" => $d["title"],
                "subtitle" => $d["subtitle"] ?? null,
                "content" => $html,
                "slug" => $slug,
                "title_image_url" => $cover,
                "meta_title" => $d["meta_title"] ?? null,
                "meta_description" => $d["meta_description"] ?? null,
                "is_draft" => $d["status"] === "draft",
                "is_published" => $d["status"] === "published",
                "published_at" =>
                    $d["status"] === "draft"
                        ? null
                        : $d["published_at"] ??
                            ($news->published_at ??
                                ($d["status"] === "published" ? now() : null)),
            ]);
            if ($created) {
                $news->user_id = $r->user()->id;
            }
            $news->save();
            $ids = collect($d["tags"])
                ->map(fn($t) => trim($t))
                ->filter()
                ->unique()
                ->map(fn($t) => Tag::firstOrCreate(["tag_name" => $t])->id);
            $news->tags()->sync($ids);
        });
        return response()->json(
            [
                "data" => $this->data($news->fresh()),
                "message" => "Đã lưu bài viết.",
            ],
            $created ? 201 : 200,
        );
    }
    public function image(Request $r, News $news)
    {
        $r->validate([
            "image" =>
                "required|image|mimes:jpg,jpeg,png,webp|max:4096|dimensions:max_width=8000,max_height=8000",
        ]);
        $path = $r->file("image")->store("news/" . $news->id, "public");
        try {
            $image = DB::transaction(function () use ($news, $path) {
                News::whereKey($news->id)->lockForUpdate()->firstOrFail();
                return $news->images()->create(["image_url" => $path]);
            });
        } catch (\Throwable $e) {
            Storage::disk("public")->delete($path);
            throw $e;
        }
        return response()->json(
            [
                "data" => [
                    "id" => $image->id,
                    "image_url" => NewsHtml::url($path),
                ],
            ],
            201,
        );
    }
    public function deleteImage(News $news, int $image)
    {
        $path = DB::transaction(function () use ($news, $image) {
            $n = News::whereKey($news->id)->lockForUpdate()->firstOrFail();
            $img = $n->images()->whereKey($image)->firstOrFail();
            if (
                $n->title_image_url === $img->image_url ||
                str_contains(
                    html_entity_decode($n->content ?? "", ENT_QUOTES),
                    NewsHtml::url($img->image_url),
                )
            ) {
                throw ValidationException::withMessages([
                    "image" =>
                        "Ảnh còn được dùng. Bỏ ảnh khỏi nội dung/ảnh bìa và lưu bài trước khi xóa.",
                ]);
            }
            $path = $img->image_url;
            $img->delete();
            return $path;
        });
        if (!preg_match("~^https?://~i", $path)) {
            Storage::disk("public")->delete($path);
        }
        return response()->noContent();
    }
    public function destroy(News $news)
    {
        $paths = DB::transaction(function () use ($news) {
            $n = News::whereKey($news->id)->lockForUpdate()->firstOrFail();
            $paths = $n
                ->images()
                ->pluck("image_url")
                ->push($n->title_image_url)
                ->filter()
                ->unique()
                ->all();
            $n->delete();
            return $paths;
        });
        foreach ($paths as $p) {
            if (!preg_match("~^https?://~i", $p)) {
                Storage::disk("public")->delete($p);
            }
        }
        return response()->noContent();
    }
    private function data(News $n, bool $full = true): array
    {
        $d = [
            "id" => $n->id,
            "title" => $n->title,
            "subtitle" => $n->subtitle,
            "slug" => $n->slug,
            "author" => $n->user?->name,
            "status" => $n->is_draft
                ? "draft"
                : (!$n->is_published
                    ? "hidden"
                    : ($n->published_at?->isFuture()
                        ? "scheduled"
                        : "published")),
            "published_at" => $n->published_at?->toIso8601String(),
            "views" => (int) $n->views,
            "title_image_url" => NewsHtml::url($n->title_image_url),
        ];
        if ($full) {
            $n->load(["images", "tags"]);
            $d += [
                "content" => NewsHtml::clean($n->content),
                "meta_title" => $n->meta_title,
                "meta_description" => $n->meta_description,
                "tags" => $n->tags->pluck("tag_name"),
                "images" => $n->images->map(
                    fn($i) => [
                        "id" => $i->id,
                        "image_url" => NewsHtml::url($i->image_url),
                    ],
                ),
                "cover_id" =>
                    $n->images->firstWhere("image_url", $n->title_image_url)
                        ?->id ?? ($n->title_image_url ? 0 : null),
            ];
            if (
                $n->title_image_url &&
                !$n->images->contains("image_url", $n->title_image_url)
            ) {
                $d["images"]->push([
                    "id" => 0,
                    "image_url" => NewsHtml::url($n->title_image_url),
                ]);
            }
        }
        return $d;
    }
}