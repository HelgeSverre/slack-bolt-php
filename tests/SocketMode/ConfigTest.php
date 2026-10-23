<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\SocketMode;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thunder\Bolt\SocketMode\Config;

final class ConfigTest extends TestCase
{
    public function test_empty_app_token_is_safe_during_boot_and_debug_output_redacts_a_configured_token(): void
    {
        $empty = new Config();
        $configured = new Config('xapp-1-local-secret');

        self::assertFalse($empty->isConfigured());
        self::assertTrue($configured->isConfigured());
        self::assertSame('[redacted]', $configured->__debugInfo()['appToken']);
        ob_start();
        var_dump($configured);
        $dump = (string) ob_get_clean();
        self::assertStringContainsString('[redacted]', $dump);
        self::assertStringNotContainsString('local-secret', $dump);
    }

    public function test_accepts_standard_and_rotating_app_token_prefixes(): void
    {
        self::assertTrue(new Config('xapp-standard')->isConfigured());
        self::assertTrue(new Config('xoxe.xapp-rotating')->isConfigured());
    }

    #[DataProvider('invalidConfigurations')]
    public function test_rejects_invalid_worker_settings(array $arguments): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Config(...$arguments);
    }

    /** @return iterable<string, array{array<string, float|int|string>}> */
    public static function invalidConfigurations(): iterable
    {
        yield 'malformed token' => [['appToken' => 'token']];
        yield 'zero receive timeout' => [['receiveTimeoutSeconds' => 0.0]];
        yield 'zero hello timeout' => [['helloTimeoutSeconds' => 0.0]];
        yield 'infinite receive timeout' => [['receiveTimeoutSeconds' => INF]];
        yield 'negative initial backoff' => [['initialBackoffMilliseconds' => -1]];
        yield 'backoff maximum before initial delay' => [
            ['initialBackoffMilliseconds' => 1000, 'maxBackoffMilliseconds' => 999],
        ];
        yield 'zero message limit' => [['maxMessageBytes' => 0]];
    }
}
