<?php

namespace App\Support;

use Illuminate\Support\Str;

class VietnameseText
{
    public static function normalize(mixed $value): string
    {
        if (is_array($value)) {
            $value = implode(' ', array_filter($value));
        }

        $text = (string) ($value ?? '');

        $text = str_replace(['đ', 'Đ'], ['d', 'D'], $text);
        $text = Str::ascii($text);
        $text = mb_strtolower($text, 'UTF-8');

        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    public static function terms(mixed $value): array
    {
        $text = self::normalize($value);

        $terms = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        return collect($terms)
            ->flatMap(function ($term) {
                $items = [$term];

                $relaxed = self::reduceRepeatedCharacters($term);

                if ($relaxed !== $term) {
                    $items[] = $relaxed;
                }

                return $items;
            })
            ->filter(fn($term) => mb_strlen($term, 'UTF-8') >= 2)
            ->unique()
            ->values()
            ->all();
    }

    public static function booleanFullTextQuery(mixed $value): string
    {
        return collect(self::terms($value))
            ->map(fn($term) => $term . '*')
            ->implode(' ');
    }

    private static function reduceRepeatedCharacters(string $term): string
    {
        return preg_replace('/([a-z])\1+/u', '$1', $term);
    }
}
