<?php

declare(strict_types=1);

namespace Thunder\Bolt\Assistant;

enum AgentSessionStatus: string
{
    case Processing = 'processing';
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';
}
