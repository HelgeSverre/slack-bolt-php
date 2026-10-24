<?php

declare(strict_types=1);

namespace Thunder\Bolt\Jobs;

final readonly class JobConfig
{
    public function __construct(
        public ?string $path = null,
    ) {}
}
