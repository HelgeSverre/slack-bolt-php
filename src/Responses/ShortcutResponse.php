<?php

declare(strict_types=1);

namespace Thunder\Bolt\Responses;

final readonly class ShortcutResponse implements Acknowledgement
{
    private function __construct() {}

    public static function empty(): self
    {
        return new self();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [];
    }
}
