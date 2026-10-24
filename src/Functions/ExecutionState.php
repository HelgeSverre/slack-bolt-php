<?php

declare(strict_types=1);

namespace Thunder\Bolt\Functions;

enum ExecutionState: string
{
    case Pending = 'pending';
    case Responding = 'responding';
    case Completed = 'completed';
    case Failed = 'failed';
    case Uncertain = 'uncertain';
}
