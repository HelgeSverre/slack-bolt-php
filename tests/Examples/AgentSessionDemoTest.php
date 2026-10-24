<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Examples;

use App\Demo\DemoConfig;
use App\Demo\Jobs\StartAssistantSession;
use App\Demo\Jobs\StartAssistantSessionHandler;
use App\Demo\ThreadState;
use App\Listeners\DurableJobs;
use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;
use Thunder\Bolt\App;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Jobs\JobContext;
use Thunder\Bolt\Jobs\JobExecution;
use Thunder\Bolt\Jobs\RetryPolicy;
use Thunder\Bolt\Jobs\SqliteJobStore;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class AgentSessionDemoTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/bolt-agent-session-' . bin2hex(random_bytes(6));
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

    public function test_a_user_renamed_session_is_persisted_without_a_slack_write(): void
    {
        $container = new GenericContainer();
        $container->singleton(DemoConfig::class, new DemoConfig($this->directory));
        $http = new RecordingHttpClient();
        $app = new App($container, new Client($http, 'test-token'), new BoltConfig(token: 'test-token'));
        $app->registerClass(DurableJobs::class);

        $app->dispatch([
            'type' => 'event_callback',
            'team_id' => 'T1',
            'event_id' => 'Ev-title',
            'event' => [
                'type' => 'agent_session_title_changed',
                'channel' => 'D1',
                'thread_ts' => '1.001',
                'user' => 'U1',
                'event_ts' => '2.001',
                'title' => 'My investigation',
            ],
        ]);

        self::assertSame([], $http->requests);
        self::assertSame(
            ['title' => 'My investigation', 'user_title' => true],
            new ThreadState($this->directory)->get('T1', 'D1', '1.001'),
        );
    }

    public function test_delayed_onboarding_does_not_overwrite_a_title_the_user_already_chose(): void
    {
        $config = new DemoConfig($this->directory);
        $state = new ThreadState($this->directory);
        $state->update('T1', 'D1', '1.001', static fn(array $current): array => [
            ...$current,
            'title' => 'My investigation',
            'user_title' => true,
        ]);
        $http = new RecordingHttpClient();
        $client = new Client($http, 'test-token');
        $context = JobContext::workspace('T1', 'D1', '1.001', 'U1');
        $store = SqliteJobStore::forTesting();
        $store->enqueue(StartAssistantSession::class, [], $context, 'onboarding', RetryPolicy::once());
        $record = $store->claim();
        self::assertNotNull($record);

        new StartAssistantSessionHandler($config, $client, $context, new JobExecution($store, $record))->handle(
            new StartAssistantSession(),
        );

        self::assertSame('agents.sessions.setStatus', basename($http->requests[0]->getUri()->getPath()));
        self::assertArrayNotHasKey('title', $http->sentPayload(0));
        self::assertSame(['title' => 'My investigation', 'user_title' => true], $state->get('T1', 'D1', '1.001'));
    }
}
