<?php

declare(strict_types=1);

namespace Thunder\Bolt\Errors;

enum ErrorHandling
{
    case Handled;
    case Rethrow;
}
