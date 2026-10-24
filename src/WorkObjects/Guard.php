<?php

declare(strict_types=1);

namespace Thunder\Bolt\WorkObjects;

/** @internal Shared validation for values crossing the Work Objects seam. */
final class Guard
{
    public static function text(string $value, int $limit = 3000): void
    {
        if (trim($value) === '' || mb_strlen($value) > $limit) {
            throw new \InvalidArgumentException("Expected non-empty text up to {$limit} characters.");
        }
    }

    public static function url(string $url): void
    {
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || !in_array($parts['scheme'] ?? '', ['https', 'http'], true)
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new \InvalidArgumentException('Expected an HTTP(S) URL without embedded credentials.');
        }
    }
}
