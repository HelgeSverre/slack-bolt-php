<?php

declare(strict_types=1);

namespace Thunder\Bolt\Exceptions;

final class MissingAcknowledgement extends \LogicException
{
    public function __construct(string $type, string $response)
    {
        parent::__construct("Slack delivery {$type} completed without a {$response}.");
    }
}
