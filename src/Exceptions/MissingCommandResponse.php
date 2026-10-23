<?php

declare(strict_types=1);

namespace Thunder\Bolt\Exceptions;

use LogicException;

final class MissingCommandResponse extends LogicException
{
    public function __construct(string $command)
    {
        parent::__construct("Slash command {$command} completed without a CommandResponse.");
    }
}
