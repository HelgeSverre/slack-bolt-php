<?php

declare(strict_types=1);

namespace Thunder\Bolt\Exceptions;

use InvalidArgumentException;

final class InvalidListener extends InvalidArgumentException
{
    public static function invalidRegex(string $pattern): self
    {
        return new self("Invalid listener regular expression: {$pattern}");
    }

    public static function invalidHandler(?string $handler = null): self
    {
        return new self(
            'A listener handler must be an invokable class, class method, closure, or function'
            . ($handler ? ": {$handler}" : '')
            . '.',
        );
    }
}
