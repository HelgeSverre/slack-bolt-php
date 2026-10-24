<?php

declare(strict_types=1);

namespace App\Demo\Functions;

use Tempest\CommandBus\Async;

/**
 * Deliberately contains only an opaque vault reference. In particular, it does
 * not contain a Slack token, client, authorization record, or execution context.
 */
#[Async]
final readonly class FormatMessageCommand
{
    public function __construct(
        public string $reference,
    ) {
        if (!preg_match('/\\A[a-f0-9]{64}\\z/', $reference)) {
            throw new \InvalidArgumentException('Invalid protected function execution reference.');
        }
    }
}
