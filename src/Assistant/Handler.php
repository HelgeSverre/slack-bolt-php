<?php

declare(strict_types=1);

namespace Thunder\Bolt\Assistant;

use Thunder\Bolt\Middleware\Middleware;
use Thunder\Bolt\Routing\HandlerTarget;

/** @internal A handler and its ordered middleware. */
final readonly class Handler
{
    /** @param list<callable|Middleware> $middleware */
    private function __construct(
        public HandlerTarget $target,
        public array $middleware,
    ) {}

    public static function fromArguments(array $arguments): self
    {
        if ($arguments === []) {
            throw new \InvalidArgumentException('An assistant handler is required.');
        }
        $handler = array_pop($arguments);
        if (is_callable($handler) && !$handler instanceof \Closure) {
            $handler = \Closure::fromCallable($handler);
        }
        if (!$handler instanceof \Closure && !is_string($handler) && !is_array($handler)) {
            throw new \InvalidArgumentException('Expected a callable or handler class.');
        }
        $middleware = [];
        foreach ($arguments as $candidate) {
            if (!$candidate instanceof Middleware && !is_callable($candidate)) {
                throw new \InvalidArgumentException('Assistant middleware must implement Middleware or be callable.');
            }
            $middleware[] = $candidate;
        }
        return new self(HandlerTarget::from($handler), $middleware);
    }
}
