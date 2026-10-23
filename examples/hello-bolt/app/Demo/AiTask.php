<?php

declare(strict_types=1);

namespace App\Demo;

use Amp\Future;

final class AiTask
{
    /** @param Future<null> $future */
    public function __construct(
        public readonly Future $future,
        public string $conversationKey,
    ) {}
}
