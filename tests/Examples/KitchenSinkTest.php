<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Examples;

use App\Demo\DemoConfig;
use App\Demo\Jobs\ShowKitchenSink;
use App\Demo\Jobs\ShowKitchenSinkHandler;
use App\Demo\Jobs\StreamKitchenSink;
use App\Demo\Jobs\StreamKitchenSinkHandler;
use App\Listeners\KitchenSink;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Tempest\CommandBus\CommandBusConfig;
use Tempest\CommandBus\CommandHandler;
use Tempest\Container\GenericContainer;
use Tempest\Reflection\MethodReflector;
use Thunder\Bolt\App;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Jobs;
use Thunder\Bolt\Jobs\JobCodec;
use Thunder\Bolt\Jobs\JobContext;
use Thunder\Bolt\Jobs\JobStatus;
use Thunder\Bolt\Jobs\SqliteJobStore;
use Thunder\Bolt\Jobs\Worker;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class KitchenSinkTest extends TestCase
{
    public function test_command_commits_before_acknowledgement_and_the_tour_creates_a_thread_scoped_child(): void
    {
        $store = SqliteJobStore::forTesting();
        $commands = new CommandBusConfig();
        foreach ([
            ShowKitchenSink::class => ShowKitchenSinkHandler::class,
            StreamKitchenSink::class => StreamKitchenSinkHandler::class,
        ] as $job => $handler) {
            $commands->addHandler(new CommandHandler(), $job, MethodReflector::fromParts($handler, 'handle'));
        }
        $codec = new JobCodec($commands);
        $jobs = new Jobs($store, $codec);
        $container = new GenericContainer();
        $directory = sys_get_temp_dir() . '/bolt-kitchen-test-' . bin2hex(random_bytes(6));
        $container->singleton(DemoConfig::class, new DemoConfig($directory));
        $http = new RecordingHttpClient();
        $client = new Client($http, 'xoxb-test');
        $config = new BoltConfig(token: 'xoxb-test');
        $app = new App($container, $client, $config, jobs: $jobs);
        $app->registerClass(KitchenSink::class);
        $body = [
            'command' => '/kickensink',
            'text' => '',
            'team_id' => 'T1',
            'channel_id' => 'D1',
            'user_id' => 'U1',
            'trigger_id' => 'stable-trigger',
        ];
        $app->dispatch($body);
        $app->dispatch($body);
        self::assertCount(1, $store->recent());
        self::assertSame([], $http->requests);
        $worker = new Worker($store, $codec, $container, $client, $config);
        self::assertSame(JobStatus::Succeeded, $worker->runOnce()?->status);
        self::assertSame('chat.postMessage', basename($http->requests[0]->getUri()->getPath()));
        $root = $http->sentPayload();
        self::assertSame('D1', $root['channel']);
        self::assertStringContainsString('Thunder kitchen sink', $root['text']);
        $child = array_find($store->recent(), static fn($record): bool => $record->type === StreamKitchenSink::class);
        self::assertNotNull($child);
        self::assertSame('1700000000.000002', $child->context->threadTs);
        self::assertSame('U1', $child->context->userId);
        self::assertSame('T1', $child->context->teamId);

        $timer = EventLoop::delay(0.01, static function () use ($store, $child): void {
            $store->cancel($child->id, 'team:T1');
        });
        try {
            $cancelled = $worker->runOnce();
        } finally {
            EventLoop::cancel($timer);
        }
        self::assertSame(JobStatus::Cancelled, $cancelled?->status);
        $payloads = array_map(static fn($request): array => json_decode(
            (string) $request->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        ), $http->requests);
        self::assertCount(1, array_filter(
            $payloads,
            static fn(array $payload): bool => ($payload['status'] ?? null) === 'processing',
        ));
        self::assertCount(1, array_filter(
            $payloads,
            static fn(array $payload): bool => ($payload['status'] ?? null) === 'active',
        ));
        self::assertSame(
            'Stopped. The durable job will settle as cancelled.',
            $payloads[array_key_last($payloads)]['text'] ?? null,
            json_encode([$cancelled?->failureType, $payloads], JSON_THROW_ON_ERROR),
        );
        self::assertFalse(is_dir($directory), 'Streaming does not need to load or create assistant context.');
    }

    public function test_stop_button_cancels_only_the_delivering_thread_and_installation(): void
    {
        $store = SqliteJobStore::forTesting();
        $commands = new CommandBusConfig();
        $commands->addHandler(
            new CommandHandler(),
            StreamKitchenSink::class,
            MethodReflector::fromParts(StreamKitchenSinkHandler::class, 'handle'),
        );
        $jobs = new Jobs($store, new JobCodec($commands));
        $matching = $jobs->in(JobContext::workspace('T1', 'C1', '1.0', 'U1'))->dispatch(
            new StreamKitchenSink(),
            key: 'one',
        );
        $otherThread = $jobs->in(JobContext::workspace('T1', 'C1', '2.0', 'U1'))->dispatch(
            new StreamKitchenSink(),
            key: 'two',
        );
        $otherTeam = $jobs->in(JobContext::workspace('T2', 'C1', '1.0', 'U1'))->dispatch(
            new StreamKitchenSink(),
            key: 'three',
        );
        $container = new GenericContainer();
        $container->singleton(DemoConfig::class, new DemoConfig('/unused'));
        $http = new RecordingHttpClient();
        $app = new App($container, new Client($http, 'xoxb-test'), new BoltConfig(token: 'xoxb-test'), jobs: $jobs);
        $app->registerClass(KitchenSink::class);
        $app->dispatch([
            'type' => 'block_actions',
            'team' => ['id' => 'T1'],
            'user' => ['id' => 'U1'],
            'channel' => ['id' => 'C1'],
            'message' => ['ts' => '1.0'],
            'actions' => [['action_id' => 'kitchen_sink_stop', 'action_ts' => '3.0']],
        ]);
        self::assertSame(JobStatus::Cancelled, $store->find($matching->id)?->status);
        self::assertSame(JobStatus::Pending, $store->find($otherThread->id)?->status);
        self::assertSame(JobStatus::Pending, $store->find($otherTeam->id)?->status);
        self::assertSame('agents.sessions.setStatus', basename($http->requests[0]->getUri()->getPath()));
        self::assertSame(['channel_id' => 'C1', 'thread_ts' => '1.0', 'status' => 'active'], $http->sentPayload());
    }
}
