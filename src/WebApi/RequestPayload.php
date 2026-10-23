<?php

declare(strict_types=1);

namespace Thunder\Bolt\WebApi;

interface RequestPayload
{
    public function toArray(): array;
}
