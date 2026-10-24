<?php

declare(strict_types=1);

namespace Thunder\Bolt\Jobs;

enum JobStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Uncertain = 'uncertain';
    case Cancelled = 'cancelled';
}
