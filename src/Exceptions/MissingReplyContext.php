<?php

declare(strict_types=1);

namespace Thunder\Bolt\Exceptions;

use LogicException;

final class MissingReplyContext extends LogicException
{
    public function __construct(string $field)
    {
        parent::__construct("This Slack payload has no {$field} for the requested reply.");
    }
}
