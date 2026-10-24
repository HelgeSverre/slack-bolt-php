<?php

declare(strict_types=1);

namespace Thunder\Bolt\Functions;

final class InvalidInputs extends \InvalidArgumentException
{
    public static function missing(string $path): self
    {
        return new self("Input \"{$path}\" is required.");
    }

    public static function type(string $path, string $expected): self
    {
        return new self("Input \"{$path}\" must be {$expected}.");
    }

    public static function null(string $path): self
    {
        return new self("Input \"{$path}\" cannot be null.");
    }

    public static function unsupported(string $path, string $reason): self
    {
        return new self("Input \"{$path}\" cannot be mapped: {$reason}.");
    }
}
