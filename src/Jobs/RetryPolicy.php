<?php

declare(strict_types=1);

namespace Thunder\Bolt\Jobs;

final readonly class RetryPolicy
{
    private function __construct(
        public int $maxAttempts,
        public int $backoffSeconds,
        public int $maxBackoffSeconds,
        public bool $safeToRepeat,
    ) {
        if (
            $maxAttempts < 1
            || $maxAttempts > 100
            || $backoffSeconds < 0
            || $maxBackoffSeconds < $backoffSeconds
            || $maxBackoffSeconds > 86400
        ) {
            throw new \InvalidArgumentException('Invalid job retry bounds.');
        }
    }

    public static function once(): self
    {
        return new self(1, 0, 0, false);
    }

    /** The caller promises that repeating the entire handler is safe. */
    public static function idempotent(int $maxAttempts = 3, int $backoffSeconds = 5, int $maxBackoffSeconds = 300): self
    {
        return new self($maxAttempts, $backoffSeconds, $maxBackoffSeconds, true);
    }

    public function delay(int $attempt): int
    {
        return (int) min($this->maxBackoffSeconds, $this->backoffSeconds * (2 ** min(30, max(0, $attempt - 1))));
    }

    public function toArray(): array
    {
        return [
            'max_attempts' => $this->maxAttempts,
            'backoff' => $this->backoffSeconds,
            'max_backoff' => $this->maxBackoffSeconds,
            'safe_to_repeat' => $this->safeToRepeat,
        ];
    }

    public static function fromArray(array $data): self
    {
        if (
            !is_int($data['max_attempts'] ?? null)
            || !is_int($data['backoff'] ?? null)
            || !is_int($data['max_backoff'] ?? null)
            || !is_bool($data['safe_to_repeat'] ?? null)
        ) {
            throw new \UnexpectedValueException('Invalid stored job retry policy.');
        }
        return new self($data['max_attempts'], $data['backoff'], $data['max_backoff'], $data['safe_to_repeat']);
    }
}
