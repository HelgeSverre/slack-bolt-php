<?php

declare(strict_types=1);

namespace Thunder\Bolt\Invocation;

use Closure;
use Thunder\Bolt\Exceptions\MiddlewareNextCalledMultipleTimes;
use Thunder\Bolt\Middleware\Middleware;

final class InvocationPipeline
{
    /** @param list<callable|Middleware> $middleware */
    public function run(array $middleware, Delivery $delivery, Closure $destination): mixed
    {
        $next = $destination;

        foreach (array_reverse($middleware) as $current) {
            $following = $next;
            $next = function () use ($current, $delivery, $following): mixed {
                $called = false;
                $guardedNext = function () use (&$called, $following): mixed {
                    if ($called) {
                        throw new MiddlewareNextCalledMultipleTimes();
                    }

                    $called = true;

                    return $following();
                };

                if ($current instanceof Middleware) {
                    return $current->handle($delivery, $guardedNext);
                }

                return $current($delivery, $guardedNext);
            };
        }

        return $next();
    }
}
