<?php

declare(strict_types=1);

namespace Thunder\Bolt\SocketMode;

/**
 * Worker settings for Slack Socket Mode.
 *
 * An empty app token is permitted so that `bolt --help`, discovery, and local
 * diagnostics work before an application has been configured. `socket:serve`
 * is the boundary that requires it.
 */
final readonly class Config
{
    /** @var null|\Closure(ContextInput): void */
    public ?\Closure $contextFactory;

    public function __construct(
        #[\SensitiveParameter]
        public string $appToken = '',
        public float $receiveTimeoutSeconds = 30.0,
        public float $helloTimeoutSeconds = 10.0,
        public int $initialBackoffMilliseconds = 500,
        public int $maxBackoffMilliseconds = 30_000,
        public int $maxMessageBytes = 1_048_576,
        ?callable $contextFactory = null,
    ) {
        $this->contextFactory = $contextFactory === null ? null : \Closure::fromCallable($contextFactory);
        if (
            $this->appToken !== ''
            && (!preg_match('/^(?:xapp-|xoxe\.xapp-)/', $this->appToken) || preg_match('/\s/', $this->appToken) === 1)
        ) {
            throw new \InvalidArgumentException(
                'Socket Mode app tokens must start with xapp- or xoxe.xapp- and contain no whitespace.',
            );
        }
        if (
            !is_finite($this->receiveTimeoutSeconds)
            || !is_finite($this->helloTimeoutSeconds)
            || $this->receiveTimeoutSeconds <= 0
            || $this->helloTimeoutSeconds <= 0
        ) {
            throw new \InvalidArgumentException('Socket Mode receive and hello timeouts must be positive.');
        }
        if (
            $this->initialBackoffMilliseconds < 0
            || $this->maxBackoffMilliseconds < $this->initialBackoffMilliseconds
        ) {
            throw new \InvalidArgumentException(
                'Socket Mode backoff must be non-negative and have an ordered maximum.',
            );
        }
        if ($this->maxMessageBytes < 1) {
            throw new \InvalidArgumentException('Socket Mode message limit must be positive.');
        }
    }

    public function isConfigured(): bool
    {
        return $this->appToken !== '';
    }

    /** @return array<string, float|int|string> */
    public function __debugInfo(): array
    {
        return [
            'appToken' => $this->appToken === '' ? '' : '[redacted]',
            'receiveTimeoutSeconds' => $this->receiveTimeoutSeconds,
            'helloTimeoutSeconds' => $this->helloTimeoutSeconds,
            'initialBackoffMilliseconds' => $this->initialBackoffMilliseconds,
            'maxBackoffMilliseconds' => $this->maxBackoffMilliseconds,
            'maxMessageBytes' => $this->maxMessageBytes,
        ];
    }
}
