<?php

declare(strict_types=1);

namespace Thunder\Bolt\WebApi;

interface TypedResponse
{
    public static function fromResponse(Response $response): static;
}
