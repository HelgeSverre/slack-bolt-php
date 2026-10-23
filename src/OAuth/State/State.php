<?php

declare(strict_types=1);

namespace Thunder\Bolt\OAuth\State;

final readonly class State
{
    public function __construct(
        public string $value,
        #[\SensitiveParameter]
        public string $cookieBinding,
        public int $expiresAt,
    ) {
        if ($value === '' || $cookieBinding === '' || $expiresAt < 1) {
            throw new \InvalidArgumentException('OAuth state requires a value, cookie binding, and expiry.');
        }
    }

    public function __debugInfo(): array
    {
        return ['value' => '[redacted]', 'cookieBinding' => '[redacted]', 'expiresAt' => $this->expiresAt];
    }
}
