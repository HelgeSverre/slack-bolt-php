<?php

declare(strict_types=1);

namespace App\Demo\Functions;

use Thunder\Bolt\Functions\ExecutionContext;

/** @internal A live context exists only in the worker which claimed its reference. */
final readonly class ClaimedFunctionExecution
{
    public function __construct(
        public string $reference,
        public ExecutionContext $context,
    ) {}

    public function __serialize(): array
    {
        throw new \LogicException('A claimed function execution must not be serialized.');
    }
}
