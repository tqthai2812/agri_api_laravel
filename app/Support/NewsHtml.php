<?php
namespace App\Support;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
final class NewsHtml
{
    public static function clean(?string $html): string
    {
        $html = trim($html ?? "");
        if (!preg_match("/<\/?[a-z][^>]*>/i", $html)) {
            $html = "<p>" . nl2br(e($html)) . "</p>";
        }
        $config = new HtmlSanitizerConfig()
            ->allowSafeElements()
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->allowLinkSchemes(["http", "https", "mailto"])
            ->allowMediaSchemes(["http", "https"])
            ->allowElement("figure")
            ->allowElement("figcaption")
            ->allowElement("img", ["src", "alt", "title", "width"])
            ->allowElement("table")
            ->allowElement("thead")
            ->allowElement("tbody")
            ->allowElement("tr")
            ->allowElement("td", ["colspan", "rowspan"])
            ->allowElement("th", ["colspan", "rowspan"])
            ->forceAttribute("a", "rel", "noopener noreferrer nofollow")
            ->withMaxInputLength(200000);
        return new HtmlSanitizer($config)->sanitize($html);
    }
    public static function text(?string $html): string
    {
        return trim(
            html_entity_decode(
                strip_tags(
                    str_replace(
                        ["</p>", "</li>", "<br>", "<br />"],
                        "\n",
                        $html ?? "",
                    ),
                ),
                ENT_QUOTES | ENT_HTML5,
                "UTF-8",
            ),
        );
    }
    public static function url(?string $path): ?string
    {
        return !$path
            ? null
            : (preg_match("~^https?://~i", $path)
                ? $path
                : asset("storage/" . ltrim($path, "/")));
    }
}