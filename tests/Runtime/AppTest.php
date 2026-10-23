<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Runtime;

use Closure;
use GuzzleHttp\Psr7\Response as PsrResponse;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Tempest\Container\GenericContainer;
use Thunder\Bolt\App;
use Thunder\Bolt\Attributes\Event;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Exceptions\DoubleAcknowledgement;
use Thunder\Bolt\Exceptions\DuplicateListener;
use Thunder\Bolt\Exceptions\HandlerFailures;
use Thunder\Bolt\Exceptions\InvalidListener;
use Thunder\Bolt\Exceptions\MiddlewareNextCalledMultipleTimes;
use Thunder\Bolt\Exceptions\MissingCommandResponse;
use Thunder\Bolt\Invocation\Delivery;
use Thunder\Bolt\Messages\Message;
use Thunder\Bolt\Middleware\Middleware;
use Thunder\Bolt\Payloads\Event as GenericEvent;
use Thunder\Bolt\Payloads\Events\AppMention;
use Thunder\Bolt\Payloads\SlashCommand;
use Thunder\Bolt\Reply;
use Thunder\Bolt\Responses\CommandResponse;
use Thunder\Bolt\Routing\Regex;
use Thunder\Bolt\WebApi\Client;

final class AppTest extends TestCase
{
    public function test_it_invokes_all_matching_event_listeners_with_scoped_payload_and_reply(): void
    {
        $transport = new RecordingTransport();
        $app = $this->app($transport);
        $calls = [];
        $app->event('app_mention', function (AppMention $event, Reply $reply) use (&$calls): void {
            $calls[] = $event->text;
            $reply->inThread('First');
        })->event('app_mention', function (AppMention $event) use (&$calls): void {
            $calls[] = $event->channelId;
        });

        self::assertNull($app->dispatch(['event' => $this->mention()]));
        self::assertSame(['hello', 'C1'], $calls);
        self::assertSame('chat.postMessage', basename($transport->requests[0]->getUri()->getPath()));
        self::assertSame(
            [
                'channel' => 'C1',
                'text' => 'First',
                'thread_ts' => '100.200',
            ],
            json_decode((string) $transport->requests[0]->getBody(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function test_global_middleware_wraps_all_matching_listener_pipelines_once(): void
    {
        $app = $this->app(new RecordingTransport());
        $trace = [];
        $app->use(new TraceMiddleware('global', $trace));
        $app->event('app_mention', new TraceMiddleware('listener', $trace), function () use (&$trace): void {
            $trace[] = 'handler:one';
        });
        $app->event('app_mention', function () use (&$trace): void {
            $trace[] = 'handler:two';
        });

        $app->dispatch(['event' => $this->mention()]);

        self::assertSame(
            ['global:before', 'listener:before', 'handler:one', 'listener:after', 'handler:two', 'global:after'],
            $trace,
        );
    }

    public function test_global_middleware_runs_once_when_no_listener_matches(): void
    {
        $app = $this->app(new RecordingTransport());
        $trace = [];
        $app->use(new TraceMiddleware('global', $trace));
        $app->event('reaction_added', static fn(): null => null);

        $app->dispatch(['event' => $this->mention()]);

        self::assertSame(['global:before', 'global:after'], $trace);
    }

    public function test_middleware_cannot_call_next_twice(): void
    {
        $app = $this->app(new RecordingTransport());
        $app->use(static function ($delivery, $next): mixed {
            $next();

            return $next();
        });
        $app->event('app_mention', static fn(): null => null);

        $this->expectException(MiddlewareNextCalledMultipleTimes::class);
        $app->dispatch(['event' => $this->mention()]);
    }

    public function test_command_requires_exactly_one_response(): void
    {
        $app = $this->app(new RecordingTransport());
        $app->command('/deploy', static fn(SlashCommand $command): CommandResponse => CommandResponse::ephemeral($command->text));

        self::assertSame(
            ['response_type' => 'ephemeral', 'text' => 'now'],
            $app->dispatch($this->command())->toArray(),
        );

        $missing = $this->app(new RecordingTransport());
        $missing->command('/deploy', static fn(): null => null);
        try {
            $missing->dispatch($this->command());
            self::fail('Expected missing command response.');
        } catch (MissingCommandResponse) {
        }

        $double = $this->app(new RecordingTransport());
        $double->command('/deploy', static fn(): CommandResponse => CommandResponse::empty())->command('/deploy', static fn(): CommandResponse => CommandResponse::empty());
        $this->expectException(DoubleAcknowledgement::class);
        $double->dispatch($this->command());
    }

    public function test_exact_attribute_and_fluent_listener_duplicate_is_rejected(): void
    {
        $app = $this->app(new RecordingTransport());
        $app->registerClass(AttributeListener::class);

        $this->expectException(DuplicateListener::class);
        $app->event('app_mention', AttributeListener::class);
    }

    public function test_incompatible_typed_payload_is_rejected_at_registration(): void
    {
        $app = $this->app(new RecordingTransport());

        $this->expectException(InvalidListener::class);
        $app->event('app_mention', static fn(SlashCommand $command): null => null);
    }

    public function test_event_listener_can_target_the_message_event_type(): void
    {
        $app = $this->app(new RecordingTransport());
        $received = null;
        $app->event('message', static function (\Thunder\Bolt\Payloads\Events\Message $event) use (&$received): void {
            $received = $event->text;
        });

        $app->dispatch(['event' => [
            'type' => 'message',
            'user' => 'U1',
            'channel' => 'C1',
            'text' => 'a message',
            'ts' => '100.200',
        ]]);

        self::assertSame('a message', $received);
    }

    public function test_regex_listener_receives_named_and_numeric_captures_for_each_selector(): void
    {
        $eventCaptures = null;
        $eventApp = $this->app(new RecordingTransport());
        $eventApp->event(Regex::of('/^(?<prefix>app)_(?<kind>mention)$/'), static function (Delivery $delivery) use (
            &$eventCaptures,
        ): void {
            $eventCaptures = $delivery->matches;
        });
        $eventApp->dispatch(['event' => $this->mention()]);

        $messageCaptures = null;
        $messageApp = $this->app(new RecordingTransport());
        $messageApp->message(Regex::of('/^(?<verb>deploy) (?<target>api)$/'), static function (Delivery $delivery) use (
            &$messageCaptures,
        ): void {
            $messageCaptures = $delivery->matches;
        });
        $messageApp->dispatch(['event' => [
            'type' => 'message',
            'user' => 'U1',
            'channel' => 'C1',
            'text' => 'deploy api',
            'ts' => '100.200',
        ]]);

        $commandCaptures = null;
        $commandApp = $this->app(new RecordingTransport());
        $commandApp->command(Regex::of('/^\\/(?<name>deploy)$/'), static function (Delivery $delivery) use (
            &$commandCaptures,
        ): CommandResponse {
            $commandCaptures = $delivery->matches;

            return CommandResponse::empty();
        });
        $commandApp->dispatch($this->command());

        self::assertSame('app_mention', $eventCaptures[0]);
        self::assertSame('app', $eventCaptures[1]);
        self::assertSame('app', $eventCaptures['prefix']);
        self::assertSame('deploy api', $messageCaptures[0]);
        self::assertSame('api', $messageCaptures[2]);
        self::assertSame('api', $messageCaptures['target']);
        self::assertSame('/deploy', $commandCaptures[0]);
        self::assertSame('deploy', $commandCaptures[1]);
        self::assertSame('deploy', $commandCaptures['name']);
    }

    public function test_each_matching_regex_listener_receives_an_isolated_delivery(): void
    {
        $app = $this->app(new RecordingTransport());
        $deliveries = [];
        $app->message(Regex::of('/(?<verb>deploy)/'), static function (Delivery $delivery) use (&$deliveries): void {
            $deliveries[] = $delivery;
        })->message(Regex::of('/(?<target>api)/'), static function (Delivery $delivery) use (&$deliveries): void {
            $deliveries[] = $delivery;
        });

        $app->dispatch(['event' => [
            'type' => 'message',
            'user' => 'U1',
            'channel' => 'C1',
            'text' => 'deploy api',
            'ts' => '100.200',
        ]]);

        self::assertCount(2, $deliveries);
        self::assertNotSame($deliveries[0], $deliveries[1]);
        self::assertSame('deploy', $deliveries[0]->matches['verb']);
        self::assertArrayNotHasKey('target', $deliveries[0]->matches);
        self::assertSame('api', $deliveries[1]->matches['target']);
        self::assertArrayNotHasKey('verb', $deliveries[1]->matches);
    }

    public function test_reply_destination_helpers_override_message_destination(): void
    {
        $transport = new RecordingTransport();
        $app = $this->app($transport);
        $app->event('app_mention', static function (Reply $reply): void {
            $reply->inChannel(Message::text('outside the thread')->inThread('999.111'));
        });

        $app->dispatch(['event' => $this->mention()]);

        self::assertSame(
            [
                'channel' => 'C1',
                'text' => 'outside the thread',
            ],
            json_decode((string) $transport->requests[0]->getBody(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function test_self_events_are_ignored_without_a_reply(): void
    {
        $app = $this->app(
            new RecordingTransport(),
            new BoltConfig(token: 'token', signingSecret: 'secret', botUserId: 'B1'),
        );
        $called = false;
        $app->event('app_mention', static function () use (&$called): void {
            $called = true;
        });

        self::assertNull($app->dispatch(['event' => [...$this->mention(), 'user' => 'B1']]));
        self::assertFalse($called);
    }

    public function test_later_listeners_run_before_the_original_failure_is_rethrown(): void
    {
        $app = $this->app(new RecordingTransport());
        $failure = new RuntimeException('First listener failed');
        $laterRan = false;
        $app->event('app_mention', static fn() => throw $failure)->event('app_mention', static function () use (
            &$laterRan,
        ): void {
            $laterRan = true;
        });

        try {
            $app->dispatch(['event' => $this->mention()]);
            self::fail('Expected the original listener failure.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertTrue($laterRan);
    }

    public function test_multiple_listener_failures_are_preserved_in_the_aggregate(): void
    {
        $app = $this->app(new RecordingTransport());
        $first = new RuntimeException('First failure');
        $second = new RuntimeException('Second failure');
        $app->event('app_mention', static fn() => throw $first)->event('app_mention', static fn() => throw $second);

        try {
            $app->dispatch(['event' => $this->mention()]);
            self::fail('Expected all listener failures.');
        } catch (HandlerFailures $exception) {
            self::assertSame([$first, $second], $exception->failures);
            self::assertSame($first, $exception->getPrevious());
        }
    }

    public function test_error_handler_receives_failures_payload_and_delivery_metadata_once(): void
    {
        $app = $this->app(new RecordingTransport());
        $failure = new RuntimeException('Listener failed');
        $reported = [];
        $app->error(static function (HandlerFailures $failures, AppMention $event, array $metadata) use (
            &$reported,
        ): void {
            $reported[] = [$failures->failures, $event->userId, $metadata];
        })->event('app_mention', static fn() => throw $failure);

        self::assertNull($app->dispatch(['event' => $this->mention()], ['retryNum' => 2]));
        self::assertSame([[[$failure], 'U1', ['retryNum' => 2]]], $reported);
    }

    public function test_unknown_events_preserve_the_original_payload_for_typed_handlers(): void
    {
        $app = $this->app(new RecordingTransport());
        $received = null;
        $app->event('future_slack_event', static function (GenericEvent $event) use (&$received): void {
            $received = $event;
        });
        $event = ['type' => 'future_slack_event', 'future_field' => ['id' => 'F1', 'enabled' => true]];

        $app->dispatch(['type' => 'event_callback', 'event' => $event]);

        self::assertInstanceOf(GenericEvent::class, $received);
        self::assertSame('future_slack_event', $received->type);
        self::assertSame($event, $received->raw);
        self::assertNull($received->channelId);
    }

    private function app(RecordingTransport $transport, ?BoltConfig $config = null): App
    {
        return new App(
            new GenericContainer(),
            new Client($transport, 'token'),
            $config ?? new BoltConfig(token: 'token', signingSecret: 'secret'),
        );
    }

    /** @return array<string, string> */
    private function mention(): array
    {
        return ['type' => 'app_mention', 'user' => 'U1', 'channel' => 'C1', 'text' => 'hello', 'ts' => '100.200'];
    }

    /** @return array<string, string> */
    private function command(): array
    {
        return [
            'command' => '/deploy',
            'text' => 'now',
            'user_id' => 'U1',
            'channel_id' => 'C1',
            'response_url' => 'https://example.test/reply',
        ];
    }
}

final class AttributeListener
{
    #[Event('app_mention')]
    public function __invoke(): void {}
}

final class TraceMiddleware implements Middleware
{
    /** @var Closure(string): void */
    private Closure $append;

    /** @param list<string> $trace */
    public function __construct(
        private string $name,
        array &$trace,
    ) {
        $this->append = static function (string $entry) use (&$trace): void {
            $trace[] = $entry;
        };
    }

    public function handle(Delivery $delivery, Closure $next): mixed
    {
        ($this->append)("{$this->name}:before");
        $result = $next();
        ($this->append)("{$this->name}:after");

        return $result;
    }
}

final class RecordingTransport implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return new PsrResponse(200, body: '{"ok":true}');
    }
}
