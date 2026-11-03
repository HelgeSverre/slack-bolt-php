<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Examples;

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use App\Demo\Ai\JobCancellation;
use App\Demo\DemoConfig;
use App\Demo\DemoHttpException;
use App\Demo\Jobs\AgentAnswer;
use App\Demo\Jobs\Answer;
use App\Demo\Jobs\AnswerHandler;
use App\Demo\OpenAiHttp;
use App\Listeners\DurableJobs;
use GuzzleHttp\Psr7\Request as PsrRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Tempest\CommandBus\CommandBusConfig;
use Tempest\CommandBus\CommandHandler;
use Tempest\Container\GenericContainer;
use Tempest\Reflection\MethodReflector;
use Thunder\Bolt\App;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Jobs;
use Thunder\Bolt\Jobs\JobCancelled;
use Thunder\Bolt\Jobs\JobCodec;
use Thunder\Bolt\Jobs\JobContext;
use Thunder\Bolt\Jobs\JobExecution;
use Thunder\Bolt\Jobs\JobStatus;
use Thunder\Bolt\Jobs\RetryPolicy;
use Thunder\Bolt\Jobs\SqliteJobStore;
use Thunder\Bolt\Jobs\Worker;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

use function Amp\delay;

final class OpenAiHttpCancellationTest extends TestCase
{
    #[DataProvider('turnTypes')]
    public function test_real_answer_handler_closes_a_partial_stream_and_worker_settles_cancelled(bool $modern): void
    {
        $directory = sys_get_temp_dir() . '/bolt-ai-transport-' . bin2hex(random_bytes(6));
        $store = SqliteJobStore::forTesting();
        $commands = new CommandBusConfig();
        $commands->addHandler(
            new CommandHandler(),
            Answer::class,
            MethodReflector::fromParts(AnswerHandler::class, 'handle'),
        );
        $commands->addHandler(
            new CommandHandler(),
            AgentAnswer::class,
            MethodReflector::fromParts(AnswerHandler::class, 'agent'),
        );
        $codec = new JobCodec($commands);
        $jobs = new Jobs($store, $codec)->in(JobContext::workspace('T1', 'D1', '1.0', 'U1'));
        $jobs->dispatch(
            $modern ? new AgentAnswer('A slow answer') : new Answer('A slow answer', thread: '1.0'),
            key: 'cancel-live',
        );
        $body = new WaitingResponseBody(firstFragment: true);
        $delegate = new class($body) implements DelegateHttpClient {
            public function __construct(
                private WaitingResponseBody $body,
            ) {}

            public function request(Request $request, Cancellation $cancellation): Response
            {
                return new Response('1.1', 200, null, ['content-type' => 'text/event-stream'], $this->body, $request);
            }
        };
        $container = new GenericContainer();
        $container->singleton(DemoConfig::class, new DemoConfig($directory, openAiKey: 'test-only'));
        $container->singleton(OpenAiHttp::class, new OpenAiHttp(http: new HttpClient($delegate, [])));
        $slack = new RecordingHttpClient();
        $worker = new Worker(
            $store,
            $codec,
            $container,
            new Client($slack, 'xoxb-test'),
            new BoltConfig(token: 'xoxb-test'),
        );
        $app = new App(
            $container,
            new Client($slack, 'xoxb-test'),
            new BoltConfig(token: 'xoxb-test'),
            jobs: new Jobs($store, $codec),
        );
        $app->registerClass(DurableJobs::class);
        $timer = EventLoop::delay(0.01, static function () use ($app): void {
            $app->dispatch([
                'type' => 'event_callback',
                'team_id' => 'T1',
                'event' => [
                    'type' => 'agent_session_stopped',
                    'channel' => 'D1',
                    'thread_ts' => '1.0',
                    'user' => 'U1',
                    'event_ts' => '2.0',
                    'streaming_message_ts' => [],
                ],
            ]);
        });
        try {
            $outcome = $worker->runOnce();
            self::assertSame(JobStatus::Cancelled, $outcome?->status);
            self::assertSame(JobCancelled::class, $outcome?->failureType);
            self::assertTrue($body->isClosed());
            self::assertSame(2, $body->reads);
            $methods = array_map(static fn($request): string => basename(
                $request->getUri()->getPath(),
            ), $slack->requests);
            self::assertContains('chat.startStream', $methods);
            self::assertContains('chat.stopStream', $methods);
            $last = $slack->sentPayload(count($slack->requests) - 1);
            self::assertSame('active', $last['status']);
            self::assertNull($worker->runOnce(), 'A cancelled model turn must not be replayed.');
        } finally {
            EventLoop::cancel($timer);
            if (is_dir($directory)) {
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST,
                );
                foreach ($files as $file) {
                    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                }
                rmdir($directory);
            }
        }
    }

    public static function turnTypes(): iterable
    {
        yield 'legacy command' => [false];
        yield 'Agent View command' => [true];
    }

    public static function stages(): iterable
    {
        yield 'waiting for headers' => [true];
        yield 'waiting for an SSE fragment' => [false];
    }

    #[DataProvider('stages')]
    public function test_durable_stop_interrupts_real_transport_waits_and_closes_the_body(bool $connecting): void
    {
        $store = SqliteJobStore::forTesting();
        $accepted = $store->enqueue(
            'test',
            [],
            JobContext::workspace('T1', 'D1', '1.0', 'U1'),
            'input',
            RetryPolicy::once(),
        );
        $record = $store->claim();
        self::assertNotNull($record);
        $bridge = new JobCancellation(new JobExecution($store, $record));
        $token = $bridge->start();
        $body = new WaitingResponseBody();
        $delegate = new class($connecting, $body) implements DelegateHttpClient {
            public function __construct(
                private bool $connecting,
                private WaitingResponseBody $body,
            ) {}

            public function request(Request $request, Cancellation $cancellation): Response
            {
                if ($this->connecting) {
                    delay(30, cancellation: $cancellation);
                }
                return new Response('1.1', 200, null, ['content-type' => 'text/event-stream'], $this->body, $request);
            }
        };
        $http = new OpenAiHttp($token, new HttpClient($delegate, []));
        $timer = EventLoop::delay(0.01, static function () use ($store, $accepted): void {
            $store->cancel($accepted->id, 'team:T1');
        });
        try {
            try {
                $http->sendStreamingRequest(
                    new PsrRequest('POST', 'https://api.openai.com/v1/responses'),
                    static function (): void {
                        self::fail('Cancelled work must not emit another response chunk.');
                    },
                );
                self::fail('The pending transport should have been cancelled.');
            } catch (DemoHttpException) {
                self::assertTrue($token->isRequested());
                if (!$connecting) {
                    self::assertTrue($body->isClosed(), 'The HTTP adapter must release a cancelled SSE body.');
                    self::assertSame(1, $body->reads);
                }
            }
            // The execution bridge restores the durable cause after a PSR transport wrapper.
            $this->expectException(JobCancelled::class);
            $bridge->checkpoint();
        } finally {
            EventLoop::cancel($timer);
            $bridge->close();
        }
    }
}

final class WaitingResponseBody implements ReadableStream, \IteratorAggregate
{
    private bool $closed = false;
    public int $reads = 0;

    public function __construct(
        private bool $firstFragment = false,
    ) {}

    public function read(?Cancellation $cancellation = null): ?string
    {
        $this->reads++;
        if ($this->firstFragment && $this->reads === 1) {
            return "event: response.output_text.delta\ndata: {\"type\":\"response.output_text.delta\",\"delta\":\"Starting the answer.\\n\"}\n\n";
        }
        delay(30, cancellation: $cancellation);
        return null;
    }

    public function isReadable(): bool
    {
        return !$this->closed;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function onClose(\Closure $onClose): void {}

    public function getIterator(): \Traversable
    {
        yield from [];
    }
}
