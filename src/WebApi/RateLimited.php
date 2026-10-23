<?php

declare(strict_types=1);

namespace Thunder\Bolt\WebApi;

final class RateLimited extends SlackApiException
{
    public function __construct(
        public readonly int $retryAfter,
    ) {
        parent::__construct('ratelimited');
    }
}
