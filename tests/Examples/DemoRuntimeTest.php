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

    public function test_missing_ai_key_finishes_locally_and_registers_its_thread_without_api_calls(): void
    {
        [$config, $http, $runtime] = $this->worker();
        $config->queue()->enqueue(new DemoJob('ai', 'hello', 'T1', 'UOWNER', 'C1', '1.001')->toArray(), 'hello-one');
        $runtime->run(static fn(): bool => count($http->requests) >= 2);

        self::assertSame('1.001', $http->sentPayload(0)['thread_ts']);
        self::assertStringContainsString('OPENAI_API_KEY', $http->sentPayload(1)['text']);
        self::assertSame('ai', new ThreadState($this->directory)->get('T1', 'C1', '1.001')['mode']);
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

    private function worker(): array
    {
        $config = new DemoConfig($this->directory, ['UOWNER']);
        $http = new RecordingHttpClient();
        $console = $this->createMock(Console::class);
        $console->expects(self::never())->method('error');
        return [$config, $http, new DemoRuntime($config, new Client($http, 'test-bot'), $console)];
    }
}
