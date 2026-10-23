<?php

declare(strict_types=1);

namespace Thunder\Bolt\Middleware;

use Closure;
use Thunder\Bolt\Invocation\Delivery;

interface Middleware
{
    public function handle(Delivery $delivery, Closure $next): mixed;
}
