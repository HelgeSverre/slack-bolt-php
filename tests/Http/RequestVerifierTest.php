<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Thunder\Bolt\Http\RequestVerifier;

final class RequestVerifierTest extends TestCase
{
    private const int NOW = 1_700_000_000;

    public function test_verifies_original_bytes_and_case_insensitive_headers(): void
    {
        $raw = 'text=hello%20world&command=%2Fping';
        $verifier = $this->verifier();
        self::assertTrue($verifier->isValid($raw, $this->headers($raw)));
        self::assertFalse($verifier->isValid(str_replace('%20', '+', $raw), $this->headers($raw)));
    }

    public function test_rejects_stale_and_future_requests_but_accepts_window_edge(): void
    {
        $raw = '{}';
        foreach ([self::NOW - 301, self::NOW + 301] as $timestamp) {
            self::assertFalse($this->verifier()->isValid($raw, $this->headers($raw, (string) $timestamp)));
        }
        foreach ([self::NOW - 300, self::NOW + 300] as $timestamp) {
            self::assertTrue($this->verifier()->isValid($raw, $this->headers($raw, (string) $timestamp)));
        }
    }

    public function test_rejects_missing_malformed_duplicate_and_empty_secret_headers(): void
    {
        $raw = '{}';
        $headers = $this->headers($raw);
        self::assertFalse($this->verifier()->isValid($raw, []));
        self::assertFalse($this->verifier()->isValid($raw, [
            ...$headers,
            'X-Slack-Signature' => 'v1=' . str_repeat('a', 64),
        ]));
        self::assertFalse($this->verifier()->isValid($raw, [...$headers, 'X-Slack-Request-Timestamp' => '1.7e9']));
        self::assertFalse($this->verifier()->isValid($raw, [
            ...$headers,
            'X-Slack-Signature' => [$headers['X-Slack-Signature'], $headers['X-Slack-Signature']],
        ]));
        self::assertFalse(new RequestVerifier('')->isValid($raw, $headers));
    }

    public function test_rejects_case_variant_duplicate_headers_in_either_order(): void
    {
        $headers = $this->headers('{}');
        self::assertFalse($this->verifier()->isValid('{}', [
            ...$headers,
            'x-slack-signature' => $headers['X-Slack-Signature'],
        ]));
        self::assertFalse($this->verifier()->isValid('{}', ['x-slack-signature' => 'invalid', ...$headers]));
        self::assertFalse($this->verifier()->isValid('{}', [...$headers, 'x-slack-request-timestamp' => '1700000000']));
    }

    private function verifier(): RequestVerifier
    {
        return new RequestVerifier('test-signing-secret', clock: new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('@1700000000');
            }
        });
    }

    /** @return array<string, string> */
    private function headers(string $raw, string $timestamp = '1700000000'): array
    {
        return [
            'X-Slack-Signature' => 'v0=' . hash_hmac('sha256', "v0:{$timestamp}:{$raw}", 'test-signing-secret'),
            'X-Slack-Request-Timestamp' => $timestamp,
        ];
    }
}
