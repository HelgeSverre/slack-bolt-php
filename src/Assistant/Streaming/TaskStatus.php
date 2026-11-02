<?php

declare(strict_types=1);

namespace Thunder\Bolt\Assistant\Streaming;

enum TaskStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Complete = 'complete';
    case Error = 'error';
}
