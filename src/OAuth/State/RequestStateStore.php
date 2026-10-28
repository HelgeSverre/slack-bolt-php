<?php

declare(strict_types=1);

namespace Thunder\Bolt\OAuth\State;

use Thunder\Bolt\OAuth\InstallRequest;

interface RequestStateStore extends StateStore
{
    /** Atomically consumes state and returns its server-side request only after verifying cookie and expiry. */
    public function consumeRequest(string $value, string $cookieBinding, int $now): ?InstallRequest;
}
