<?php

declare(strict_types=1);

namespace Thunder\Bolt\Exceptions;

use LogicException;

final class MiddlewareNextCalledMultipleTimes extends LogicException
{
    public function __construct()
    {
        parent::__construct('Middleware may call its next callback only once.');
    }
}
