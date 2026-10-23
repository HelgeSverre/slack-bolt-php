<?php

declare(strict_types=1);

namespace Thunder\Bolt\WebApi;

class SlackApiException extends \RuntimeException
{
    public function __construct(
        public readonly string $error,
        public readonly array $data = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct('Slack API error: ' . $error, previous: $previous);
    }
}
