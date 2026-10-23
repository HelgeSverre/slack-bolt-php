<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\OAuth;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\OAuth\Installation;
use Thunder\Bolt\OAuth\InstallationQuery;
use Thunder\Bolt\OAuth\Stores\FileInstallationStore;

final class FileRotationConcurrencyTest extends TestCase
{
    public function test_two_php_workers_redeem_a_single_use_refresh_token_only_once(): void
    {
        $directory = sys_get_temp_dir() . '/bolt-rotation-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $store = new FileInstallationStore($directory . '/store');
        $store->save(Installation::fromOAuthResponse([
            'team' => ['id' => 'T1'],
            'access_token' => 'old-access',
            'refresh_token' => 'single-use-refresh',
            'expires_in' => 1,
        ], 1));
        $runner = $directory . '/worker.php';
        file_put_contents($runner, <<<'PHP'
            <?php
            require $argv[1];
            $directory = $argv[2];
            $transport = new class($directory) implements Psr\Http\Client\ClientInterface {
                public function __construct(private string $directory) {}
                public function sendRequest(Psr\Http\Message\RequestInterface $request): Psr\Http\Message\ResponseInterface {
                    file_put_contents($this->directory.'/redemptions', "refresh\n", FILE_APPEND | LOCK_EX);
                    usleep(200_000);
                    return new GuzzleHttp\Psr7\Response(200, [], '{"ok":true,"access_token":"fresh-access","refresh_token":"fresh-refresh","expires_in":43200}');
                }
            };
            $clock = new class implements Psr\Clock\ClockInterface {
                public function now(): DateTimeImmutable { return new DateTimeImmutable('@10000'); }
            };
            $store = new Thunder\Bolt\OAuth\Stores\FileInstallationStore($directory.'/store');
            $rotator = new Thunder\Bolt\OAuth\TokenRotator(
                new Thunder\Bolt\OAuth\OAuthConfig('client', 'secret', 'https://example.test/slack/oauth_redirect'),
                new Thunder\Bolt\WebApi\Client($transport, ''), $store, $clock,
            );
            $rotator->refreshBot(new Thunder\Bolt\OAuth\InstallationQuery('T1'));
            PHP);
        $processes = [];
        try {
            for ($index = 0; $index < 2; $index++) {
                $process = proc_open(
                    [PHP_BINARY, $runner, dirname(__DIR__, 2) . '/vendor/autoload.php', $directory],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                );
                self::assertIsResource($process);
                fclose($pipes[0]);
                $processes[] = [$process, $pipes];
            }
            foreach ($processes as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]);
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $output . $errors);
            }
            self::assertSame("refresh\n", file_get_contents($directory . '/redemptions'));
            self::assertSame('fresh-refresh', $store->fetch(new InstallationQuery('T1'))->botRefreshToken);
        } finally {
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            ) as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($directory);
        }
    }
}
