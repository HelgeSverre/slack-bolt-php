<?php

declare(strict_types=1);

namespace Thunder\Bolt\OAuth\State;

interface StateStore
{
    public function put(State $state): void;

    /**
     * Atomically verifies and consumes a state value. A mismatched or expired
     * state is consumed too, so it cannot be replayed.
     */
    public function consume(string $value, string $cookieBinding, int $now): bool;
}
