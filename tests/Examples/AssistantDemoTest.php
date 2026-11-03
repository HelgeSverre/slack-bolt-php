<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Examples;

use App\Demo\AssistantDemo;
use App\Demo\DemoConfig;
use App\Demo\Jobs\Answer;
use App\Demo\Jobs\AnswerHandler;
use App\Demo\Jobs\StartAssistantSession;
use App\Demo\Jobs\StartAssistantSessionHandler;
use PHPUnit\Framework\TestCase;
use Tempest\CommandBus\CommandBusConfig;
use Tempest\CommandBus\CommandHandler;
use Tempest\Container\GenericContainer;
use Tempest\Reflection\MethodReflector;
use Thunder\Bolt\App;
use Thunder\Bolt\Assistant;
use Thunder\Bolt\Assistant\Stores\StoredThreadContextStore;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Conversation\Stores\FileConversationStore;
use Thunder\Bolt\Jobs;
use Thunder\Bolt\Jobs\JobCodec;
use Thunder\Bolt\Jobs\SqliteJobStore;
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

    public function test_thread_start_commits_a_durable_assistant_turn_before_acknowledging(): void
    {
        [$app, $store, $http] = $this->app();

        self::assertNull($app->dispatch($this->started()));
        self::assertNull($app->dispatch($this->started()));
        self::assertSame([], $http->requests);

        self::assertCount(1, $store->recent());
        $record = $store->recent()[0];
        self::assertSame(StartAssistantSession::class, $record->type);
        self::assertSame([], $record->payload);
        self::assertSame('T-installed', $record->context->teamId);
        self::assertSame('D-assistant', $record->context->channelId);
        self::assertSame('100.001', $record->context->threadTs);
        self::assertSame('U-user', $record->context->userId);
    }

    public function test_context_is_persisted_before_a_user_message_is_queued_without_outbound_http(): void
    {
        [$app, $store, $http] = $this->app();

        $app->dispatch($this->started());
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
        self::assertCount(2, $store->recent());
        $record = $this->answer($store, 'Explain & summarize this thread.');
        self::assertSame(Answer::class, $record->type);
        self::assertSame(
            ['text' => 'Explain & summarize this thread.', 'thread' => '100.001', 'title' => null],
            $record->payload,
        );
        self::assertSame('T-installed', $record->context->teamId);
        self::assertSame('D-assistant', $record->context->channelId);
        self::assertSame('100.001', $record->context->threadTs);
    }

    public function test_a_root_direct_message_creates_a_local_assistant_thread_without_delaying_the_ack(): void
    {
        [$app, $store, $http] = $this->app();

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

        self::assertCount(1, $store->recent());
        $rootJob = $this->answer($store, 'Explain the assistant runtime.');
        self::assertSame(Answer::class, $rootJob->type);
        self::assertSame(
            ['text' => 'Explain the assistant runtime.', 'thread' => '200.001', 'title' => null],
            $rootJob->payload,
        );

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
        self::assertCount(2, $store->recent());
        $followUp = $this->answer($store, 'What context did you save?');
        self::assertSame(
            ['text' => 'What context did you save?', 'thread' => '200.001', 'title' => null],
            $followUp->payload,
        );
    }

    /** @return array{App, SqliteJobStore, RecordingHttpClient} */
    private function app(): array
    {
        $config = new DemoConfig($this->directory);
        $http = new RecordingHttpClient();
        $client = new Client($http, 'xoxb-test');
        $container = new GenericContainer();
        $container->singleton(DemoConfig::class, $config);
        $commands = new CommandBusConfig();
        $commands->addHandler(
            new CommandHandler(),
            Answer::class,
            MethodReflector::fromParts(AnswerHandler::class, 'handle'),
        );
        $commands->addHandler(
            new CommandHandler(),
            StartAssistantSession::class,
            MethodReflector::fromParts(StartAssistantSessionHandler::class, 'handle'),
        );
        $store = SqliteJobStore::forTesting();
        $jobs = new Jobs($store, new JobCodec($commands));
        $contextStore = new StoredThreadContextStore(new FileConversationStore($this->directory
        . '/assistant-context'));
        $app = new App($container, $client, new BoltConfig(token: 'xoxb-test'), jobs: $jobs)->assistant(
            Assistant::make()
                ->threadStarted([AssistantDemo::class, 'started'])
                ->threadContextChanged([AssistantDemo::class, 'contextChanged'])
                ->userMessage([AssistantDemo::class, 'message'])
                ->contextStore($contextStore),
        );
        $app->event('message', [AssistantDemo::class, 'directMessage']);

        return [$app, $store, $http];
    }

    private function answer(SqliteJobStore $store, string $text): \Thunder\Bolt\Jobs\JobRecord
    {
        $record = array_find(
            $store->recent(),
            static fn($record): bool => $record->type === Answer::class && $record->payload['text'] === $text,
        );

        self::assertNotNull($record);

        return $record;
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
