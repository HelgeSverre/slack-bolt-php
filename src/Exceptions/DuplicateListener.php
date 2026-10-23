<?php

declare(strict_types=1);

namespace Thunder\Bolt\Exceptions;

use LogicException;

final class DuplicateListener extends LogicException
{
    public static function forSources(string $first, string $second): self
    {
        return new self("The listener is registered twice: {$first}; {$second}.");
    }
}
