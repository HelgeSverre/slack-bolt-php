<?php

declare(strict_types=1);

namespace Thunder\Bolt\Http;

use Psr\Clock\ClockInterface;

final readonly class RequestVerifier
{
    public function __construct(
        #[\SensitiveParameter]
        private string $signingSecret,
        private int $maxSkewSeconds = 300,
        private ?ClockInterface $clock = null,
    ) {}

    /** @param array<string, string|list<string>> $headers */
    public function isValid(string $rawBody, array $headers): bool
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $name = strtolower($name);
            if (array_key_exists($name, $normalized)) {
                return false;
            }
            $normalized[$name] = $value;
        }
        $headers = $normalized;
        $signature = $this->header($headers, 'x-slack-signature');
        $timestamp = $this->header($headers, 'x-slack-request-timestamp');
        if (
            $this->signingSecret === ''
            || !preg_match('/\Av0=[a-f0-9]{64}\z/', $signature)
            || !ctype_digit($timestamp)
        ) {
            return false;
        }
        $seconds = filter_var($timestamp, FILTER_VALIDATE_INT);
        if (
            !is_int($seconds)
            || abs(($this->clock?->now()->getTimestamp() ?? time()) - $seconds) > $this->maxSkewSeconds
        ) {
            return false;
        }
        $expected = 'v0=' . hash_hmac('sha256', 'v0:' . $timestamp . ':' . $rawBody, $this->signingSecret);

        return hash_equals($expected, $signature);
    }

    /** @param array<string, string|list<string>> $headers */
    private function header(array $headers, string $name): string
    {
        $value = $headers[$name] ?? '';

        return is_array($value) ? (count($value) === 1 ? $value[0] : '') : $value;
    }
}
