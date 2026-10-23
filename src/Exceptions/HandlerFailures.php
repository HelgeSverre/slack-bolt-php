<?php

declare(strict_types=1);

namespace Thunder\Bolt\Exceptions;

use RuntimeException;
use Throwable;

final class HandlerFailures extends RuntimeException
{
    /** @param list<Throwable> $failures */
    public function __construct(
        public readonly array $failures,
    ) {
        parent::__construct(sprintf('%d Slack listener(s) failed.', count($failures)), previous: $failures[0] ?? null);
    }
}
