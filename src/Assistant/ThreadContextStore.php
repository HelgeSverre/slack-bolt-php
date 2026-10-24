<?php

declare(strict_types=1);

namespace Thunder\Bolt\Assistant;

use Thunder\Bolt\WebApi\Client;

interface ThreadContextStore
{
    public function get(ThreadIdentity $identity, Client $client): ?ThreadContext;

    public function save(ThreadIdentity $identity, ThreadContext $context, Client $client): void;
}
