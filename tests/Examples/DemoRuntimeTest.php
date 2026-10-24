<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Examples;

use App\Demo\DemoConfig;
use App\Demo\DemoJob;
use App\Demo\DemoRuntime;
use App\Demo\Pet\PetStore;
use App\Demo\ThreadState;
use PHPUnit\Framework\TestCase;
use Tempest\Console\Console;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class DemoRuntimeTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/bolt-runtime-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function test_worker_renders_and_persists_a_care_action_from_its_queue(): void
    {
        [$config, $http, $runtime] = $this->worker();
        $config->queue()->enqueue(new DemoJob('pet', 'feed', 'T1', 'UOWNER', 'C1')->toArray(), 'feed-one');
        $runtime->run(static fn(): bool => count($http->requests) >= 1);

        self::assertSame('chat.postMessage', basename($http->requests[0]->getUri()->getPath()));
        self::assertSame('C1', $http->sentPayload()['channel']);
        self::assertCount(4, $http->sentPayload()['blocks']);
        $state = new PetStore($this->directory . '/pet.json')->snapshot('T1');
        self::assertSame(1, $state['contributors']['UOWNER']['actions']);
        self::assertNull($config->queue()->claim());
    }

    public function test_legacy_ai_spool_jobs_fail_safe_without_running_the_model(): void
    {
        $config = new DemoConfig($this->directory, ['UOWNER']);
        $http = new RecordingHttpClient();
        $reported = false;
        $console = $this->createMock(Console::class);
        $console
            ->expects(self::once())
            ->method('error')
            ->willReturnCallback(function () use (&$reported, $console): Console {
                $reported = true;
                return $console;
            });
        $runtime = new DemoRuntime($config, new Client($http, 'test-bot'), $console);
        $config->queue()->enqueue(new DemoJob('ai', 'hello', 'T1', 'UOWNER', 'C1', '1.001')->toArray(), 'hello-one');
        $runtime->run(static function () use (&$reported): bool {
            return $reported;
        });

        self::assertTrue($reported);
        self::assertSame('chat.postMessage', basename($http->requests[0]->getUri()->getPath()));
        self::assertSame('1.001', $http->sentPayload(0)['thread_ts']);
        self::assertStringContainsString('could not finish', $http->sentPayload(0)['text']);
        self::assertNull(new ThreadState($this->directory)->get('T1', 'C1', '1.001'));
        self::assertNull($config->queue()->claim());
    }

    public function test_worker_streams_a_real_local_shell_to_one_screen_then_closes_it_on_shutdown(): void
    {
        [$config, $http, $runtime] = $this->worker();
        $config->queue()->enqueue(
            new DemoJob('terminal', "printf 'SLACK_TTY_OK\\n'", 'T1', 'UOWNER', 'C1')->toArray(),
            'terminal-one',
        );
        $deadline = microtime(true) + 3;
        $runtime->run(static function () use ($http, $deadline): bool {
            foreach ($http->requests as $request) {
                if (str_contains((string) $request->getBody(), 'SLACK_TTY_OK')) {
                    return true;
                }
            }
            return microtime(true) >= $deadline;
        });

        $bodies = array_map(static fn($request): string => (string) $request->getBody(), $http->requests);
        self::assertStringContainsString('SLACK_TTY_OK', implode("\n", $bodies));
        $last = $http->sentPayload(count($http->requests) - 1);
        self::assertSame('chat.update', basename($http->requests[count($http->requests) - 1]->getUri()->getPath()));
        self::assertStringContainsString('closed', $last['text']);
        self::assertSame('closed', new ThreadState($this->directory)->get('T1', 'C1', $last['ts'])['mode']);
    }

    public function test_home_care_uses_the_shared_pet_and_only_publishes_to_the_acting_user(): void
    {
        [$config, $http, $runtime] = $this->worker();
        $store = new PetStore($this->directory . '/pet.json');
        $store->care('T1', 'UOTHER', 'clean', 'channel-care');
        $config->queue()->enqueue(
            new DemoJob('pet_home', 'feed', 'T1', 'UOWNER', '', operation: 'care')->toArray(),
            'home-feed',
        );
        $config->queue()->enqueue(
            new DemoJob('pet_home', 'play', 'T1', 'UOWNER', '', operation: 'care')->toArray(),
            'home-cooldown',
        );
        $runtime->run(static fn(): bool => count($http->requests) >= 2);

        foreach ($http->requests as $request) {
            self::assertSame('views.publish', basename($request->getUri()->getPath()));
        }
        self::assertSame('UOWNER', $http->sentPayload()['user_id']);
        self::assertSame('home', $http->sentPayload()['view']['type']);
        $first = json_encode($http->sentPayload(), JSON_THROW_ON_ERROR);
        self::assertStringContainsString('UOTHER', $first);
        self::assertStringContainsString('Fed Boltling. +12 XP.', $first);
        self::assertStringContainsString('cooldown', json_encode($http->sentPayload(1), JSON_THROW_ON_ERROR));
        self::assertSame(1, $store->snapshot('T1')['contributors']['UOWNER']['actions']);
        self::assertSame(24, $store->snapshot('T1')['xp']);
        self::assertSame(0, $store->snapshot('T2')['xp']);
    }

    private function worker(): array
    {
        $config = new DemoConfig($this->directory, ['UOWNER']);
        $http = new RecordingHttpClient();
        $console = $this->createMock(Console::class);
        $console->expects(self::never())->method('error');
        return [$config, $http, new DemoRuntime($config, new Client($http, 'test-bot'), $console)];
    }
}
