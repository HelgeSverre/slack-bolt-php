<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Examples;

use App\Demo\AssistantDemo;
use App\Demo\DemoConfig;
use App\Demo\DemoJob;
use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;
use Thunder\Bolt\App;
use Thunder\Bolt\Assistant;
use Thunder\Bolt\Assistant\Stores\StoredThreadContextStore;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Conversation\Stores\FileConversationStore;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class AssistantDemoTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/bolt-assistant-demo-' . bin2hex(random_bytes(6));
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

    public function test_thread_start_acknowledges_before_the_worker_sets_up_the_native_assistant_thread(): void
    {
        [$app, $config, $client, $http] = $this->app();

        self::assertNull($app->dispatch($this->started()));
        self::assertSame([], $http->requests);

        $claimed = $config->queue()->claim();
        self::assertNotNull($claimed);
        $job = DemoJob::fromArray($claimed['job']);
        self::assertSame('assistant', $job->mode);
        self::assertSame('started', $job->operation);
        self::assertSame('T-installed', $job->team);
        self::assertSame('D-assistant', $job->channel);
        self::assertSame('100.001', $job->thread);
        self::assertSame('U-user', $job->user);

        new AssistantDemo($config)->start($job, $client);

        self::assertSame('assistant.threads.setStatus', basename($http->requests[0]->getUri()->getPath()));
        self::assertSame('assistant.threads.setTitle', basename($http->requests[1]->getUri()->getPath()));
        self::assertSame('assistant.threads.setSuggestedPrompts', basename($http->requests[2]->getUri()->getPath()));
        self::assertSame('chat.postMessage', basename($http->requests[3]->getUri()->getPath()));
        self::assertSame('D-assistant', $http->sentPayload(3)['channel']);
        self::assertSame('100.001', $http->sentPayload(3)['thread_ts']);
        self::assertSame('assistant.threads.setStatus', basename($http->requests[4]->getUri()->getPath()));
        self::assertSame('', $http->sentPayload(4)['status']);
    }

    public function test_context_is_persisted_before_a_user_message_is_queued_without_outbound_http(): void
    {
        [$app, $config, $_client, $http] = $this->app();

        $app->dispatch($this->started());
        self::assertNotNull($config->queue()->claim());
        self::assertSame([], $http->requests);

        $app->dispatch([
            'type' => 'event_callback',
            'team_id' => 'WRONG-TEAM',
            'authorizations' => [['team_id' => 'T-installed', 'is_enterprise_install' => false]],
            'event' => [
                'type' => 'message',
                'channel_type' => 'im',
                'channel' => 'D-assistant',
                'user' => 'U-user',
                'text' => 'Explain &amp; summarize this thread.',
                'ts' => '101.001',
                'thread_ts' => '100.001',
            ],
        ]);

        self::assertSame([], $http->requests);
        $claimed = $config->queue()->claim();
        self::assertNotNull($claimed);
        $job = DemoJob::fromArray($claimed['job']);
        self::assertSame('message', $job->operation);
        self::assertSame('Explain & summarize this thread.', $job->text);
        self::assertSame('T-installed', $job->team);
        self::assertSame('D-assistant', $job->channel);
        self::assertSame('100.001', $job->thread);
    }

    public function test_a_root_direct_message_creates_a_local_assistant_thread_without_delaying_the_ack(): void
    {
        [$app, $config, $_client, $http] = $this->app();

        self::assertNull($app->dispatch([
            'type' => 'event_callback',
            'team_id' => 'WRONG-TEAM',
            'authorizations' => [['team_id' => 'T-installed', 'is_enterprise_install' => false]],
            'event' => [
                'type' => 'message',
                'channel_type' => 'im',
                'channel' => 'D-assistant',
                'user' => 'U-user',
                'text' => 'Explain the assistant runtime.',
                'ts' => '200.001',
            ],
        ]));
        self::assertSame([], $http->requests);

        $claimed = $config->queue()->claim();
        self::assertNotNull($claimed);
        $rootJob = DemoJob::fromArray($claimed['job']);
        self::assertSame('assistant', $rootJob->mode);
        self::assertSame('message', $rootJob->operation);
        self::assertSame('T-installed', $rootJob->team);
        self::assertSame('200.001', $rootJob->thread);
        self::assertSame(['root' => true], $rootJob->payload);

        $app->dispatch([
            'type' => 'event_callback',
            'team_id' => 'WRONG-TEAM',
            'authorizations' => [['team_id' => 'T-installed', 'is_enterprise_install' => false]],
            'event' => [
                'type' => 'message',
                'channel_type' => 'im',
                'channel' => 'D-assistant',
                'user' => 'U-user',
                'text' => 'What context did you save?',
                'ts' => '201.001',
                'thread_ts' => '200.001',
            ],
        ]);

        self::assertSame([], $http->requests);
        $claimed = $config->queue()->claim();
        self::assertNotNull($claimed);
        $followUp = DemoJob::fromArray($claimed['job']);
        self::assertSame('message', $followUp->operation);
        self::assertSame('200.001', $followUp->thread);
        self::assertSame([], $followUp->payload);
        self::assertNull($config->queue()->claim());
    }

    /** @return array{App, DemoConfig, Client, RecordingHttpClient} */
    private function app(): array
    {
        $config = new DemoConfig($this->directory);
        $http = new RecordingHttpClient();
        $client = new Client($http, 'xoxb-test');
        $container = new GenericContainer();
        $container->singleton(DemoConfig::class, $config);
        $store = new StoredThreadContextStore(new FileConversationStore($this->directory . '/assistant-context'));
        $app = new App($container, $client, new BoltConfig(token: 'xoxb-test'))->assistant(
            Assistant::make()
                ->threadStarted([AssistantDemo::class, 'started'])
                ->threadContextChanged([AssistantDemo::class, 'contextChanged'])
                ->userMessage([AssistantDemo::class, 'message'])
                ->contextStore($store),
        );
        $app->registerClass(AssistantDemo::class);

        return [$app, $config, $client, $http];
    }

    /** @return array<string, mixed> */
    private function started(): array
    {
        return [
            'type' => 'event_callback',
            'team_id' => 'WRONG-TEAM',
            'authorizations' => [['team_id' => 'T-installed', 'is_enterprise_install' => false]],
            'event' => [
                'type' => 'assistant_thread_started',
                'event_ts' => '100.001',
                'assistant_thread' => [
                    'channel_id' => 'D-assistant',
                    'thread_ts' => '100.001',
                    'user_id' => 'U-user',
                    'context' => ['team_id' => 'T-focus', 'case' => 'CASE-42'],
                ],
            ],
        ];
    }
}
