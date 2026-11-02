<?php

declare(strict_types=1);

namespace App;

use Thunder\Bolt\Jobs\Job;

final readonly class LocalReceipt implements Job
{
    public function __construct(
        public string $name,
    ) {}
}
