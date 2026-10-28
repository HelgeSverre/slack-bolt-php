<?php

declare(strict_types=1);

namespace Thunder\Bolt\Invocation;

use Thunder\Bolt\Errors\FailureStage;

/** @internal Retains assistant handler identity until the App reports the original error. */
final class InvocationFailure extends \RuntimeException
{
    public function __construct(
        public readonly \Throwable $error,
        public readonly Delivery $delivery,
        public readonly FailureStage $stage,
    ) {
        parent::__construct('Invocation failed.', previous: $error);
    }
}
