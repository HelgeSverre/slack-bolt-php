<?php

declare(strict_types=1);

namespace Thunder\Bolt\OAuth;

enum InstallStatus
{
    case Installed;
    case Denied;
    case InvalidState;
    case MissingCode;
}
