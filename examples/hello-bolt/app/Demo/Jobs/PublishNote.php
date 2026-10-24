<?php

declare(strict_types=1);

namespace App\Demo\Jobs;

use Thunder\Bolt\Jobs\Job;

final readonly class PublishNote implements Job
{
    public function __construct(
        public string $text,
    ) {}
}
