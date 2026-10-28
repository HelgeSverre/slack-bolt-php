<?php

declare(strict_types=1);

namespace Thunder\Bolt\OAuth;

final class InstallHookFailed extends \RuntimeException
{
    public function __construct(
        public readonly InstallResult $result,
        \Throwable $previous,
    ) {
        parent::__construct('An installation hook failed after the OAuth outcome was determined.', previous: $previous);
    }
}
