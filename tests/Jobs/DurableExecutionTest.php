<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Jobs;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Tempest\CommandBus\CommandBusConfig;
use Tempest\CommandBus\CommandHandler;
use Tempest\Container\GenericContainer;
use Tempest\Reflection\MethodReflector;
use Thunder\Bolt\App;
use Thunder\Bolt\Authorization\Authorization;
use Thunder\Bolt\Authorization\CallbackAuthorizer;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Conversation\ConversationContext;
use Thunder\Bolt\Conversation\Stores\MemoryConversationStore;
use Thunder\Bolt\Http\Receiver;
use Thunder\Bolt\Jobs;
use Thunder\Bolt\Jobs\Job;
use Thunder\Bolt\Jobs\JobCancelled;
use Thunder\Bolt\Jobs\JobCodec;
use Thunder\Bolt\Jobs\JobContext;
use Thunder\Bolt\Jobs\JobExecution;
use Thunder\Bolt\Jobs\JobStatus;
use Thunder\Bolt\Jobs\LeaseLost;
use Thunder\Bolt\Jobs\RetryPolicy;
use Thunder\Bolt\Jobs\SqliteJobStore;
use Thunder\Bolt\Jobs\Worker;
use Thunder\Bolt\Payloads\Events\AppMention;
use Thunder\Bolt\Reply;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class DurableExecutionTest extends TestCase
{
    private MutableJobClock $clock;
    private SqliteJobStore $store;
    private JobCodec $codec;
    private Jobs $jobs;
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/thunder-jobs-' . bin2hex(random_bytes(6));
        $this->clock = new MutableJobClock();
        $this->store = SqliteJobStore::open($this->directory . '/queue.sqlite', $this->clock);
        $commands = new CommandBusConfig();
        $commands->addHandler(
            new CommandHandler(),
            PublishNote::class,
            MethodReflector::fromParts(PublishNoteHandler::class, 'handle'),
        );
        $this->codec = new JobCodec($commands);
        $this->jobs = new Jobs($this->store, $this->codec)->in(JobContext::workspace('T1', 'C1', '1.0', 'U1'));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function test_intake_survives_reopening_and_deduplicates_by_tenant_and_type(): void
    {
        $first = $this->jobs->dispatch(new PublishNote('hello'), key: 'delivery:1');
        $duplicate = $this->jobs->dispatch(new PublishNote('hello'), key: 'delivery:1');
        $other = $this->jobs->in(JobContext::workspace('T2', 'C1', '1.0', 'U1'))->dispatch(
            new PublishNote('hello'),
            key: 'delivery:1',
        );
        self::assertFalse($first->duplicate);
        self::assertTrue($duplicate->duplicate);
        self::assertSame($first->id, $duplicate->id);
        self::assertNotSame($first->id, $other->id);
        $reopened = SqliteJobStore::open($this->directory . '/queue.sqlite', $this->clock);
        self::assertSame('hello', $reopened->find($first->id)?->payload['text']);
        self::assertCount(2, $reopened->recent());
        self::assertSame(0600, fileperms($this->directory . '/queue.sqlite') & 0777);
        $this->expectException(\Thunder\Bolt\Jobs\IntakeFailed::class);
        $this->jobs->dispatch(new PublishNote('changed'), key: 'delivery:1');
    }

    public function test_atomic_claim_blocks_a_second_connection_and_stale_completion_is_rejected(): void
    {
        $result = $this->jobs->dispatch(new PublishNote('hello'), key: 'one');
        $other = SqliteJobStore::open($this->directory . '/queue.sqlite', $this->clock);
        $first = $this->store->claim(10);
        self::assertSame($result->id, $first?->id);
        self::assertNull($other->claim(10));
        $this->clock->time += 10;
        self::assertSame(1, $other->recoverExpired());
        self::assertSame(JobStatus::Uncertain, $other->find($result->id)?->status);
        self::assertNull($other->claim());
        $this->expectException(LeaseLost::class);
        $this->store->settle($first, JobStatus::Succeeded);
    }

    public function test_expired_idempotent_attempts_recover_with_backoff_and_bounded_attempts(): void
    {
        $result = $this->jobs->dispatch(new PublishNote('hello'), key: 'safe', retry: RetryPolicy::idempotent(2, 5));
        $first = $this->store->claim(10);
        $this->clock->time += 10;
        self::assertSame(1, $this->store->recoverExpired());
        self::assertSame(JobStatus::Pending, $this->store->find($result->id)?->status);
        self::assertNull($this->store->claim());
        $this->clock->time += 5;
        $second = $this->store->claim(10);
        self::assertSame(2, $second?->attempts);
        self::assertNotSame($first?->leaseToken(), $second?->leaseToken());
        $this->clock->time += 10;
        $this->store->recoverExpired();
        self::assertSame(JobStatus::Uncertain, $this->store->find($result->id)?->status);
    }

    public function test_pending_and_running_cancellation_are_tenant_and_thread_scoped(): void
    {
        $first = $this->jobs->dispatch(new PublishNote('first'), key: '1');
        $second = $this->jobs->dispatch(new PublishNote('second'), key: '2');
        $other = $this->jobs->in(JobContext::workspace('T2', 'C1', '1.0'))->dispatch(
            new PublishNote('other'),
            key: '1',
        );
        $running = $this->store->claim();
        self::assertFalse($this->jobs->in(JobContext::workspace('T2'))->cancel($first->id));
        self::assertSame(2, $this->jobs->cancelThread());
        self::assertSame(JobStatus::Pending, $this->store->find($other->id)?->status);
        $statuses = [$this->store->find($first->id)?->status, $this->store->find($second->id)?->status];
        self::assertContains(JobStatus::Running, $statuses);
        self::assertContains(JobStatus::Cancelled, $statuses);
        $this->expectException(JobCancelled::class);
        new JobExecution($this->store, $running)->checkpoint();
    }

    public function test_heartbeat_renews_only_the_live_lease_and_manual_retry_is_explicit(): void
    {
        $result = $this->jobs->dispatch(new PublishNote('one'), key: 'manual');
        $job = $this->store->claim(10);
        $this->clock->time += 9;
        new JobExecution($this->store, $job, 10)->heartbeat();
        $this->clock->time += 2;
        self::assertSame(0, $this->store->recoverExpired());
        $this->clock->time += 9;
        $this->store->recoverExpired();
        try {
            $this->store->retry($result->id);
            self::fail('Unsafe recovery must require an explicit decision.');
        } catch (\LogicException) {
            self::assertSame(JobStatus::Uncertain, $this->store->find($result->id)?->status);
        }
        $this->store->retry($result->id, allowRepeat: true);
        $retry = $this->store->claim();
        self::assertSame(2, $retry?->attempts);
        self::assertSame(2, $retry?->attemptLimit);
        self::assertSame(JobStatus::Running, $retry?->status);
    }

    public function test_worker_reauthorizes_and_builds_fresh_context_and_constructor_services_for_each_job(): void
    {
        $first = $this->jobs->dispatch(new PublishNote('first'), key: '1');
        $second = $this->jobs->in(JobContext::workspace('T2', 'C2', '2.0', 'U2'))->dispatch(
            new PublishNote('second'),
            key: '2',
        );
        $http = new RecordingHttpClient();
        $container = new GenericContainer();
        $client = new Client($http, 'root-token-must-not-leak');
        $container->singleton(Client::class, $client);
        $conversations = new MemoryConversationStore();
        $conversations->set(JobContext::workspace('T1', 'C1')->conversationKey(), ['live' => 'newer-state']);
        $authorizer = new CallbackAuthorizer(static fn($source): Authorization => new Authorization(
            botToken: 'fresh-' . $source->teamId,
            teamId: $source->teamId,
        ));
        $worker = new Worker(
            $this->store,
            $this->codec,
            $container,
            $client,
            new BoltConfig('stale'),
            $authorizer,
            $conversations,
        );
        self::assertSame(JobStatus::Succeeded, $worker->runOnce()?->status);
        self::assertSame(JobStatus::Succeeded, $worker->runOnce()?->status);
        self::assertNull($worker->runOnce());
        self::assertCount(2, $http->requests);
        $tokens = array_map(static fn($request): string => $request->getHeaderLine('Authorization'), $http->requests);
        sort($tokens);
        self::assertSame(['Bearer fresh-T1', 'Bearer fresh-T2'], $tokens);
        self::assertSame('first|newer-state', $http->sentPayload(0)['text']);
        self::assertSame('second|none', $http->sentPayload(1)['text']);
        self::assertSame('C1', $http->sentPayload(0)['channel']);
        self::assertSame('C2', $http->sentPayload(1)['channel']);
        $client->call('auth.test');
        self::assertSame('Bearer root-token-must-not-leak', $http->requests[2]->getHeaderLine('Authorization'));
        self::assertSame(JobStatus::Succeeded, $this->store->find($first->id)?->status);
        self::assertSame(JobStatus::Succeeded, $this->store->find($second->id)?->status);
    }

    public function test_http_persists_before_response_and_redelivery_after_failed_ack_does_not_duplicate_work(): void
    {
        $config = new BoltConfig('token', 'signing-secret');
        $app = new App(
            new GenericContainer(),
            new Client(new RecordingHttpClient(), 'token'),
            $config,
            jobs: new Jobs($this->store, $this->codec),
        );
        $calls = 0;
        $app->event('app_mention', function (AppMention $event, Jobs $jobs) use (&$calls): void {
            $result = $jobs->dispatch(new PublishNote($event->text));
            $other = SqliteJobStore::open($this->directory . '/queue.sqlite', $this->clock);
            self::assertSame(JobStatus::Pending, $other->find($result->id)?->status);
            if (++$calls === 1) {
                throw new \RuntimeException('Simulated failure after durable intake, before receiver acknowledgement.');
            }
            self::assertTrue($result->duplicate);
        });
        $body = json_encode([
            'type' => 'event_callback',
            'event_id' => 'Ev1',
            'team_id' => 'T1',
            'event' => ['type' => 'app_mention', 'user' => 'U1', 'channel' => 'C1', 'ts' => '1.0', 'text' => 'hello'],
        ], JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $headers = [
            'x-slack-request-timestamp' => $timestamp,
            'x-slack-signature' => 'v0=' . hash_hmac('sha256', 'v0:' . $timestamp . ':' . $body, 'signing-secret'),
        ];
        $receiver = new Receiver($app, $config);
        self::assertSame(500, $receiver->handle($body, $headers)->status);
        self::assertSame(200, $receiver->handle($body, $headers)->status);
        self::assertCount(1, $this->store->recent());
    }

    public function test_worker_retries_safe_failures_and_persists_cooperative_cancellation(): void
    {
        $commands = new CommandBusConfig();
        $commands->addHandler(
            new CommandHandler(),
            FailingJob::class,
            MethodReflector::fromParts(FailingHandler::class, 'handle'),
        );
        $codec = new JobCodec($commands);
        $jobs = new Jobs($this->store, $codec)->in(JobContext::workspace('T1'));
        $result = $jobs->dispatch(new FailingJob(), key: 'retry', retry: RetryPolicy::idempotent(2, 5));
        $worker = new Worker(
            $this->store,
            $codec,
            new GenericContainer(),
            new Client(new RecordingHttpClient(), 'token'),
            new BoltConfig('token'),
        );
        self::assertSame(JobStatus::Pending, $worker->runOnce()?->status);
        self::assertNull($worker->runOnce());
        $this->clock->time += 5;
        self::assertSame(JobStatus::Failed, $worker->runOnce()?->status);
        self::assertSame(2, $this->store->find($result->id)?->attempts);
        $this->store->retry($result->id);
        $this->jobs->cancel($result->id);
        self::assertNull($worker->runOnce());
        self::assertSame(JobStatus::Cancelled, $this->store->find($result->id)?->status);
    }

    public function test_cancellation_wins_over_a_concurrent_retry_settlement(): void
    {
        $result = $this->jobs->dispatch(new PublishNote('one'), key: 'cancel-race', retry: RetryPolicy::idempotent());
        $job = $this->store->claim();
        $this->store->cancel($result->id);
        $this->store->settle($job, JobStatus::Pending);
        self::assertSame(JobStatus::Cancelled, $this->store->find($result->id)?->status);
        self::assertNull($this->store->claim());
    }

    public function test_constructor_injection_is_delivery_scoped_and_agent_stop_cancels_only_that_thread(): void
    {
        $jobs = new Jobs($this->store, $this->codec);
        $app = new App(
            new GenericContainer(),
            new Client(new RecordingHttpClient(), 'token'),
            new BoltConfig('token'),
            jobs: $jobs,
        );
        $app->event('app_mention', [ConstructorJobsListener::class, 'handle']);
        $app->dispatch([
            'type' => 'event_callback',
            'event_id' => 'Ev1',
            'team_id' => 'T1',
            'event' => ['type' => 'app_mention', 'channel' => 'C1', 'user' => 'U1', 'ts' => '1.0', 'text' => 'queued'],
        ]);
        $app->event('agent_session_stopped', static function (Jobs $jobs): void {
            $jobs->cancelThread();
        });
        $app->dispatch([
            'type' => 'event_callback',
            'event_id' => 'EvStop',
            'team_id' => 'T1',
            'event' => [
                'type' => 'agent_session_stopped',
                'channel' => 'C1',
                'user' => 'U1',
                'thread_ts' => '1.0',
                'event_ts' => '2.0',
                'streaming_message_ts' => [],
            ],
        ]);
        self::assertSame(JobStatus::Cancelled, $this->store->recent()[0]->status);
        self::assertSame('1.0', $this->store->recent()[0]->context->threadTs);
    }

    public function test_assistant_pipeline_preserves_delivery_bound_jobs_and_original_thread(): void
    {
        $app = new App(
            new GenericContainer(),
            new Client(new RecordingHttpClient(), 'token'),
            new BoltConfig('token'),
            jobs: new Jobs($this->store, $this->codec),
        );
        $app->assistant(\Thunder\Bolt\Assistant::make()
            ->threadStarted(static fn(): null => null)
            ->userMessage(static function (Jobs $jobs): void {
                $jobs->dispatch(new PublishNote('assistant reply'));
            }));
        $app->dispatch([
            'type' => 'event_callback',
            'event_id' => 'EvAssistant',
            'team_id' => 'T1',
            'event' => [
                'type' => 'message',
                'channel_type' => 'im',
                'channel' => 'D1',
                'user' => 'U1',
                'thread_ts' => '1.0',
                'ts' => '2.0',
                'text' => 'hello',
            ],
        ]);
        self::assertCount(1, $this->store->recent());
        self::assertSame('1.0', $this->store->recent()[0]->context->threadTs);
        self::assertSame('D1', $this->store->recent()[0]->context->channelId);
    }

    public function test_socket_mode_commits_before_ack_and_deduplicates_across_transport_envelopes(): void
    {
        $http = new RecordingHttpClient([new \GuzzleHttp\Psr7\Response(
            200,
            [],
            '{"ok":true,"url":"wss://socket.example.test/"}',
        )]);
        $client = new Client($http, 'token');
        $app = new App(
            new GenericContainer(),
            $client,
            new BoltConfig('token'),
            jobs: new Jobs($this->store, $this->codec),
        );
        $app->event('app_mention', static function (Jobs $jobs): void {
            $jobs->dispatch(new PublishNote('one'));
        });
        $body = [
            'type' => 'event_callback',
            'event_id' => 'EvSocket',
            'team_id' => 'T1',
            'event' => ['type' => 'app_mention', 'channel' => 'C1', 'user' => 'U1', 'ts' => '1.0', 'text' => 'hello'],
        ];
        $connection = new class($body, $this->store) implements \Thunder\Bolt\SocketMode\Transport\Connection {
            public int $received = 0;
            public array $sent = [];
            private array $frames;

            public function __construct(
                array $body,
                private SqliteJobStore $store,
            ) {
                $this->frames = ['{"type":"hello"}'];
                foreach (['envelope-one', 'envelope-two'] as $id) {
                    $this->frames[] = json_encode([
                        'type' => 'events_api',
                        'envelope_id' => $id,
                        'payload' => $body,
                    ], JSON_THROW_ON_ERROR);
                }
            }

            public function receive(float $timeoutSeconds): ?string
            {
                $this->received++;
                return array_shift($this->frames);
            }

            public function send(string $payload): void
            {
                TestCase::assertCount(1, $this->store->recent());
                TestCase::assertSame(JobStatus::Pending, $this->store->recent()[0]->status);
                $this->sent[] = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            }

            public function close(): void {}
        };
        $connector = new class($connection) implements \Thunder\Bolt\SocketMode\Transport\Connector {
            public function __construct(
                private \Thunder\Bolt\SocketMode\Transport\Connection $connection,
            ) {}

            public function connect(string $url): \Thunder\Bolt\SocketMode\Transport\Connection
            {
                return $this->connection;
            }
        };
        new \Thunder\Bolt\SocketMode\Runner(
            $app,
            $client,
            new \Thunder\Bolt\SocketMode\Config(appToken: 'xapp-fixture'),
            $connector,
        )->run(static fn(): bool => $connection->received >= 3);
        self::assertSame([['envelope_id' => 'envelope-one'], ['envelope_id' => 'envelope-two']], $connection->sent);
        self::assertCount(1, $this->store->recent());
    }

    public function test_unregistered_types_schema_drift_and_live_services_cannot_enter_named_job_payloads(): void
    {
        try {
            $this->codec->decode(PublishNote::class, ['text' => 'one', 'removed_field' => 'two']);
            self::fail('A changed job schema needs explicit migration.');
        } catch (\Thunder\Bolt\Jobs\PermanentFailure) {
            self::assertCount(0, $this->store->recent());
        }
        $commands = new CommandBusConfig();
        $commands->addHandler(
            new CommandHandler(),
            CapturedServiceJob::class,
            MethodReflector::fromParts(CapturedServiceHandler::class, 'handle'),
        );
        $jobs = new Jobs($this->store, new JobCodec($commands))->in(JobContext::workspace('T1'));
        $this->expectException(\Thunder\Bolt\Jobs\IntakeFailed::class);
        $jobs->dispatch(
            new CapturedServiceJob(new Client(new RecordingHttpClient(), 'do-not-store-this')),
            key: 'unsafe',
        );
    }

    public function test_invalid_job_intake_cannot_be_swallowed_by_the_general_error_hook(): void
    {
        $commands = new CommandBusConfig();
        $commands->addHandler(
            new CommandHandler(),
            CapturedServiceJob::class,
            MethodReflector::fromParts(CapturedServiceHandler::class, 'handle'),
        );
        $client = new Client(new RecordingHttpClient(), 'do-not-persist');
        $app = new App(
            new GenericContainer(),
            $client,
            new BoltConfig('token'),
            jobs: new Jobs($this->store, new JobCodec($commands)),
        );
        $app->event('app_mention', static function (Jobs $jobs) use ($client): void {
            $jobs->dispatch(new CapturedServiceJob($client));
        });
        $reported = false;
        $app->error(static function () use (&$reported): void {
            $reported = true;
        });
        try {
            $app->dispatch([
                'type' => 'event_callback',
                'event_id' => 'EvInvalid',
                'team_id' => 'T1',
                'event' => [
                    'type' => 'app_mention',
                    'channel' => 'C1',
                    'user' => 'U1',
                    'ts' => '1.0',
                    'text' => 'hello',
                ],
            ]);
            self::fail('Validation failure must remain an intake failure.');
        } catch (\Thunder\Bolt\Jobs\IntakeFailed $exception) {
            self::assertInstanceOf(\InvalidArgumentException::class, $exception->getPrevious());
            self::assertTrue($reported);
            self::assertCount(0, $this->store->recent());
        }
    }

    public function test_worker_integration_uses_the_apps_current_fluent_authorizer(): void
    {
        $this->jobs->dispatch(new PublishNote('hello'), key: 'reauthorize');
        $http = new RecordingHttpClient();
        $container = new GenericContainer();
        $client = new Client($http, 'root');
        $config = new BoltConfig('root');
        $app = new App($container, $client, $config);
        $app->authorizeUsing(static fn($source): Authorization => new Authorization(
            botToken: 'old',
            teamId: $source->teamId,
        ));
        $commands = new CommandBusConfig();
        $commands->addHandler(
            new CommandHandler(),
            PublishNote::class,
            MethodReflector::fromParts(PublishNoteHandler::class, 'handle'),
        );
        $container->singleton(App::class, $app);
        $container->singleton(Client::class, $client);
        $container->singleton(BoltConfig::class, $config);
        $container->singleton(SqliteJobStore::class, $this->store);
        $container->singleton(CommandBusConfig::class, $commands);
        $worker = new \Thunder\Bolt\Integration\JobWorkerInitializer()->initialize($container);
        $app->authorizeUsing(static fn($source): Authorization => new Authorization(
            botToken: 'latest',
            teamId: $source->teamId,
        ));
        self::assertSame(JobStatus::Succeeded, $worker->runOnce()?->status);
        self::assertSame('Bearer latest', $http->requests[0]->getHeaderLine('Authorization'));
    }

    public function test_recovery_summaries_exclude_payloads_and_live_lease_credentials(): void
    {
        $this->jobs->dispatch(new PublishNote('private-job-input'), key: 'one');
        $record = $this->store->claim();
        $summary = json_encode($record, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('private-job-input', $summary);
        self::assertStringNotContainsString($record->leaseToken(), $summary);
        $this->expectException(\LogicException::class);
        serialize($record);
    }

    public function test_file_factory_refuses_an_ephemeral_sqlite_database(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SqliteJobStore::open(':memory:');
    }

    public function test_jobs_reject_an_unsupported_container_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new App(
            $this->createStub(\Tempest\Container\Container::class),
            new Client(new RecordingHttpClient(), 'token'),
            new BoltConfig('token'),
            jobs: new Jobs($this->store, $this->codec),
        );
    }

    public function test_file_store_requires_a_private_directory_for_database_and_sidecars(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX directory modes do not describe Windows ACLs.');
        }
        $shared = $this->directory . '/shared';
        mkdir($shared, 0755);
        chmod($shared, 0755);
        try {
            SqliteJobStore::open($shared . '/queue.sqlite');
            self::fail('Shared directories require a dedicated private execution subdirectory.');
        } catch (\RuntimeException) {
            self::assertFileDoesNotExist($shared . '/queue.sqlite');
            self::assertSame(0755, fileperms($shared) & 0777);
        } finally {
            rmdir($shared);
        }
    }

    public function test_scheduled_jobs_wait_until_due_and_default_failure_is_not_retried(): void
    {
        $future = $this->jobs->dispatch(
            new PublishNote('later'),
            key: 'later',
            after: new DateTimeImmutable('@' . ($this->clock->time + 30)),
        );
        self::assertNull($this->store->claim());
        $this->clock->time += 30;
        self::assertSame($future->id, $this->store->claim()?->id);
        $commands = new CommandBusConfig();
        $commands->addHandler(
            new CommandHandler(),
            FailingJob::class,
            MethodReflector::fromParts(FailingHandler::class, 'handle'),
        );
        $codec = new JobCodec($commands);
        $result = new Jobs($this->store, $codec)->in(JobContext::workspace('T1'))->dispatch(
            new FailingJob(),
            key: 'once',
        );
        $worker = new Worker(
            $this->store,
            $codec,
            new GenericContainer(),
            new Client(new RecordingHttpClient(), 'token'),
            new BoltConfig('token'),
        );
        self::assertSame(JobStatus::Failed, $worker->runOnce()?->status);
        self::assertNull($worker->runOnce());
        self::assertSame(1, $this->store->find($result->id)?->attempts);
    }

    public function test_failed_durable_intake_does_not_acknowledge_slack_successfully(): void
    {
        $config = new BoltConfig('token', 'signing-secret');
        $app = new App(
            new GenericContainer(),
            new Client(new RecordingHttpClient(), 'token'),
            $config,
            jobs: new Jobs($this->store, $this->codec),
        );
        $app->event('app_mention', static function (Jobs $jobs): void {
            $jobs->dispatch(new PublishNote('one'));
        });
        $cause = null;
        $app->error(static function (\Thunder\Bolt\Exceptions\HandlerFailures $failure) use (&$cause): void {
            $cause = $failure->getPrevious()?->getPrevious();
        });
        $body = json_encode([
            'type' => 'event_callback',
            'event_id' => 'EvLock',
            'team_id' => 'T1',
            'event' => ['type' => 'app_mention', 'user' => 'U1', 'channel' => 'C1', 'ts' => '1.0', 'text' => 'hello'],
        ], JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $headers = [
            'x-slack-request-timestamp' => $timestamp,
            'x-slack-signature' => 'v0=' . hash_hmac('sha256', 'v0:' . $timestamp . ':' . $body, 'signing-secret'),
        ];
        $blocking = new PDO('sqlite:' . $this->directory . '/queue.sqlite');
        $blocking->exec('BEGIN IMMEDIATE');
        try {
            $response = new Receiver($app, $config)->handle($body, $headers);
            self::assertSame(500, $response->status);
            self::assertInstanceOf(\PDOException::class, $cause);
            self::assertSame(5, $cause->errorInfo[1]); // SQLITE_BUSY, not a different handler failure.
        } finally {
            $blocking->exec('ROLLBACK');
        }
        self::assertCount(0, $this->store->recent());
    }

    public function test_live_workflow_context_is_not_implicitly_persisted_as_a_normal_job(): void
    {
        $app = new App(
            new GenericContainer(),
            new Client(new RecordingHttpClient(), 'token'),
            new BoltConfig('token'),
            jobs: new Jobs($this->store, $this->codec),
        );
        $app->function('format', static function (Jobs $jobs): void {
            $jobs->dispatch(new PublishNote('one'), key: 'workflow');
        });
        try {
            $app->dispatch([
                'type' => 'event_callback',
                'team_id' => 'T1',
                'event' => [
                    'type' => 'function_executed',
                    'function' => ['callback_id' => 'format'],
                    'function_execution_id' => 'Fx1',
                    'bot_access_token' => 'workflow-token-never-serialize',
                    'inputs' => [],
                ],
            ]);
            self::fail('Workflow credentials require an explicit protected reference.');
        } catch (\Thunder\Bolt\Jobs\IntakeFailed) {
            self::assertCount(0, $this->store->recent());
        }
        self::assertStringNotContainsString(
            'workflow-token-never-serialize',
            (string) file_get_contents($this->directory . '/queue.sqlite'),
        );
    }

    public function test_worker_observes_cancellation_during_the_handler_and_holds_explicit_uncertainty(): void
    {
        $commands = new CommandBusConfig();
        $commands->addHandler(
            new CommandHandler(),
            ControlledJob::class,
            MethodReflector::fromParts(ControlledHandler::class, 'handle'),
        );
        $codec = new JobCodec($commands);
        $jobs = new Jobs($this->store, $codec)->in(JobContext::workspace('T1'));
        $container = new GenericContainer();
        $container->singleton(SqliteJobStore::class, $this->store);
        $worker = new Worker(
            $this->store,
            $codec,
            $container,
            new Client(new RecordingHttpClient(), 'token'),
            new BoltConfig('token'),
        );
        $cancelled = $jobs->dispatch(new ControlledJob('cancel'), key: 'cancel', retry: RetryPolicy::idempotent());
        self::assertSame(JobStatus::Cancelled, $worker->runOnce()?->status);
        self::assertSame(1, $this->store->find($cancelled->id)?->attempts);
        $uncertain = $jobs->dispatch(new ControlledJob('uncertain'), key: 'unknown', retry: RetryPolicy::idempotent());
        self::assertSame(JobStatus::Uncertain, $worker->runOnce()?->status);
        self::assertNull($worker->runOnce());
        self::assertSame(1, $this->store->find($uncertain->id)?->attempts);
        self::assertStringNotContainsString('private-input', json_encode(
            $this->store->find($uncertain->id)?->summary(),
            JSON_THROW_ON_ERROR,
        ));
    }

    public function test_business_key_deduplicates_across_receipts_but_never_across_destinations(): void
    {
        $context = JobContext::workspace('T1', 'C1', '1.0');
        $first = $this->jobs->in($context->withDeliveryId('Ev1'))->dispatch(new PublishNote('one'), key: 'invoice:123');
        $second = $this->jobs->in($context->withDeliveryId('Ev2'))->dispatch(
            new PublishNote('one'),
            key: 'invoice:123',
        );
        self::assertSame($first->id, $second->id);
        self::assertTrue($second->duplicate);
        $this->expectException(\Thunder\Bolt\Jobs\IntakeFailed::class);
        $this->jobs->in(JobContext::workspace('T1', 'C2', '1.0'))->dispatch(new PublishNote('one'), key: 'invoice:123');
    }

    public function test_two_worker_processes_compete_for_one_job_then_abandoned_work_is_recovered(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('Process execution is unavailable.');
        }
        $job = $this->jobs->dispatch(new PublishNote('one'), key: 'process-race');
        $script = 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';' . <<<'PHP'
            $clock = new class implements Psr\Clock\ClockInterface {
                public function now(): DateTimeImmutable { return new DateTimeImmutable('@1700000000'); }
            };
            $store = Thunder\Bolt\Jobs\SqliteJobStore::open($argv[1], $clock);
            file_put_contents($argv[2], 'ready');
            $deadline = microtime(true) + 5;
            while (!is_file($argv[3])) {
                if (microtime(true) > $deadline) exit(2);
                usleep(1000);
            }
            echo $store->claim(10)?->id ?? 'none';
            PHP;
        $processes = [];
        $streams = [];
        try {
            foreach ([1, 2] as $number) {
                $pipes = [];
                $process = proc_open(
                    [
                        PHP_BINARY,
                        '-r',
                        $script,
                        $this->directory . '/queue.sqlite',
                        $this->directory . '/ready' . $number,
                        $this->directory . '/go',
                    ],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                );
                self::assertIsResource($process);
                fclose($pipes[0]);
                $processes[] = $process;
                $streams[] = $pipes;
            }
            $deadline = microtime(true) + 4;
            while (
                (!is_file($this->directory . '/ready1') || !is_file($this->directory . '/ready2'))
                && microtime(true) < $deadline
            ) {
                usleep(1000);
            }
            self::assertFileExists($this->directory . '/ready1');
            self::assertFileExists($this->directory . '/ready2');
            file_put_contents($this->directory . '/go', 'go');
            $claims = [];
            foreach ($processes as $index => $process) {
                $claims[] = stream_get_contents($streams[$index][1]);
                self::assertSame('', stream_get_contents($streams[$index][2]));
                fclose($streams[$index][1]);
                fclose($streams[$index][2]);
                self::assertSame(0, proc_close($process));
            }
            sort($claims);
            $expected = [$job->id, 'none'];
            sort($expected);
            self::assertSame($expected, $claims);
            $this->clock->time += 10;
            self::assertSame(1, $this->store->recoverExpired());
            self::assertSame(JobStatus::Uncertain, $this->store->find($job->id)?->status);
        } finally {
            foreach ($processes as $process) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
        }
    }
}

final class MutableJobClock implements ClockInterface
{
    public int $time = 1700000000;

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $this->time);
    }
}

final readonly class PublishNote implements Job
{
    public function __construct(
        public string $text,
    ) {}
}

final readonly class PublishNoteHandler
{
    public function __construct(
        private Reply $reply,
        private JobExecution $execution,
        private ConversationContext $conversation,
    ) {}

    #[CommandHandler]
    public function handle(PublishNote $job): void
    {
        $this->execution->checkpoint();
        $state = $this->conversation->value([]);
        $this->reply->inThread($job->text . '|' . ($state['live'] ?? 'none'));
    }
}

final readonly class FailingJob implements Job
{
    public function __construct() {}
}

final readonly class FailingHandler
{
    #[CommandHandler]
    public function handle(FailingJob $job): void
    {
        throw new \RuntimeException('Transient test failure');
    }
}

final readonly class ControlledJob implements Job
{
    public function __construct(
        public string $mode,
    ) {}
}

final readonly class ControlledHandler
{
    public function __construct(
        private SqliteJobStore $store,
        private JobExecution $execution,
    ) {}

    #[CommandHandler]
    public function handle(ControlledJob $job): void
    {
        if ($job->mode === 'cancel') {
            $this->store->cancel($this->execution->job->id);
            $this->execution->checkpoint();
            throw new \LogicException('Cancellation must have interrupted this handler.');
        }
        throw new \Thunder\Bolt\Jobs\UncertainOutcome('private-input must never appear in operator summaries');
    }
}

final readonly class ConstructorJobsListener
{
    public function __construct(
        private Jobs $jobs,
    ) {}

    public function handle(AppMention $event): void
    {
        $this->jobs->dispatch(new PublishNote($event->text));
    }
}

final readonly class CapturedServiceJob implements Job
{
    public function __construct(
        public Client $client,
    ) {}
}

final readonly class CapturedServiceHandler
{
    #[CommandHandler]
    public function handle(CapturedServiceJob $job): void {}
}
