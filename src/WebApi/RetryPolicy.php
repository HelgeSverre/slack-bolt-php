<?php

declare(strict_types=1);

namespace Thunder\Bolt\WebApi;

use Psr\Http\Message\ResponseInterface;

final readonly class RetryPolicy
{
    private function __construct(
        public int $maxRetries,
        public float $initialDelay,
        public float $maxDelay,
        public float $maxTotalWait,
        public bool $retryTransientErrors,
        public bool $jitter,
    ) {
        if ($maxRetries < 0 || $maxRetries > 100) {
            throw new \InvalidArgumentException('Retries must be between zero and 100.');
        }
        foreach ([$initialDelay, $maxDelay, $maxTotalWait] as $delay) {
            if (!is_finite($delay) || $delay < 0 || $delay > 86400) {
                throw new \InvalidArgumentException('Retry delays must be finite and between zero and 86400 seconds.');
            }
        }
        if ($initialDelay > $maxDelay) {
            throw new \InvalidArgumentException('The initial retry delay cannot exceed the maximum delay.');
        }
    }

    public static function rateLimits(int $maxRetries = 3, float $maxTotalWait = 60): self
    {
        return new self($maxRetries, 0.5, 30, $maxTotalWait, false, true);
    }

    // Transient failures can occur after Slack has already processed a write.
    public static function transientErrors(
        int $maxRetries = 3,
        float $initialDelay = 0.5,
        float $maxDelay = 30,
        float $maxTotalWait = 60,
        bool $jitter = true,
    ): self {
        return new self($maxRetries, $initialDelay, $maxDelay, $maxTotalWait, true, $jitter);
    }

    public function delay(?ResponseInterface $response, int $retry): ?float
    {
        if ($retry >= $this->maxRetries) {
            return null;
        }
        if ($response?->getStatusCode() === 429) {
            $seconds = filter_var($response->getHeaderLine('Retry-After'), FILTER_VALIDATE_INT);
            return is_int($seconds) && $seconds >= 0 ? (float) $seconds : 1.0;
        }
        if (!$this->retryTransientErrors || $response !== null && $response->getStatusCode() < 500) {
            return null;
        }
        $delay = min($this->maxDelay, $this->initialDelay * (2 ** min($retry, 32)));
        return $this->jitter ? ($delay * random_int(500, 1000)) / 1000 : $delay;
    }
}
