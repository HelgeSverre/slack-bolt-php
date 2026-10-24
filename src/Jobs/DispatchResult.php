<?php

declare(strict_types=1);

namespace Thunder\Bolt\Jobs;

final readonly class DispatchResult
{
    public function __construct(
        public string $id,
        public bool $duplicate,
    ) {}
}
