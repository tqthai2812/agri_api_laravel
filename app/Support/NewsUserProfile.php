<?php

namespace App\Support;

final class NewsUserProfile
{
    public static function avatar(?string $path): ?string
    {
        if (!$path) {
            return null;
        }
        if (preg_match("~^https?://~i", $path)) {
            return $path;
        }
        if (preg_match("~^[a-z][a-z0-9+.-]*:|^//~i", $path)) {
            return null;
        }
        $path = ltrim($path, "/");
        return str_starts_with($path, "storage/")
            ? asset($path)
            : NewsHtml::url($path);
    }
}