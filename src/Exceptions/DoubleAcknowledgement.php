<?php

declare(strict_types=1);

namespace Thunder\Bolt\Exceptions;

use LogicException;

final class DoubleAcknowledgement extends LogicException
{
    public function __construct(string $command, string $response = 'CommandResponse')
    {
        $kind = $response === 'CommandResponse' ? 'Slash command' : 'Slack delivery';
        parent::__construct("{$kind} {$command} returned more than one {$response}.");
    }
}
