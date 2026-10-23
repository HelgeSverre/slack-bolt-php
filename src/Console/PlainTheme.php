<?php

declare(strict_types=1);

namespace Thunder\Bolt\Console;

use Tempest\Highlight\Theme;
use Tempest\Highlight\Tokens\TokenType;

final readonly class PlainTheme implements Theme
{
    public function before(TokenType $tokenType): string
    {
        return '';
    }

    public function after(TokenType $tokenType): string
    {
        return '';
    }

    public function escape(string $content): string
    {
        return $content;
    }
}
