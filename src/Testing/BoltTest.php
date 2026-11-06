<?php

declare(strict_types=1);

namespace Thunder\Bolt\Testing;

use Tempest\CommandBus\CommandBusConfig;
use Tempest\CommandBus\CommandHandler;
use Tempest\Container\GenericContainer;
use Tempest\Reflection\MethodReflector;
use Thunder\Bolt\App;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Http\Receiver;
use Thunder\Bolt\Jobs;
use Thunder\Bolt\Jobs\Job;
use Thunder\Bolt\Jobs\JobCodec;
use Thunder\Bolt\Jobs\SqliteJobStore;
use Thunder\Bolt\WebApi\Client;

/** Isolated application harness: no environment loading, disk stores, or Slack network traffic. */
final class BoltTest
{
    public readonly App $app;
    public readonly FakeSlack $slack;
    private readonly GenericContainer $container;
    private readonly CommandBusConfig $commands;
    private readonly ?SqliteJobStore $store;
    private readonly Receiver $receiver;
    private readonly BoltConfig $config;
    private int $deliveries = 0;
    private ?\Throwable $failure = null;

    private function __construct(bool $jobs)
    {
        $this->container = new GenericContainer();
        $this->slack = new FakeSlack();
        $token = bin2hex(random_bytes(16));
        $client = new Client(http: $this->slack, token: $token);
        $this->config = new BoltConfig(
            token: $token,
            signingSecret: bin2hex(random_bytes(16)),
            botUserId: 'U_BOLT_TEST',
            botId: 'B_BOLT_TEST',
            conversationStoreDriver: 'memory',
        );
        $this->commands = new CommandBusConfig();
        $this->store = $jobs ? SqliteJobStore::forTesting() : null;
        $queue = $this->store === null ? null : new Jobs($this->store, new JobCodec($this->commands));
        $this->container->singleton(Client::class, $client);
        $this->container->singleton(BoltConfig::class, $this->config);
        $this->app = new App($this->container, $client, $this->config, jobs: $queue);
        $this->receiver = new Receiver(
            $this->app,
            $this->config,
            errorHandler: function (\Thunder\Bolt\Errors\FailureContext $failure): \Thunder\Bolt\Http\Response {
                $this->failure = $failure->error;
                return new \Thunder\Bolt\Http\Response(500);
            },
        );
    }

    public static function make(bool $jobs = false): self
    {
        return new self($jobs);
    }

    public function bind(object $value, ?string $as = null): self
    {
        $type = $as ?? $value::class;
        if ($type === Client::class) {
            throw new \InvalidArgumentException(
                'Configure fake Slack responses through $bolt->slack->respondsWith(); do not replace the captured client.',
            );
        }
        $this->container->singleton($type, $value);
        return $this;
    }

    /** @param class-string ...$classes */
    public function listeners(string ...$classes): self
    {
        foreach ($classes as $class) {
            $this->app->registerClass($class);
        }
        return $this;
    }

    /**
     * Registers real Tempest command handlers, without running queued work.
     * @param class-string ...$classes
     */
    public function jobHandlers(string ...$classes): self
    {
        if ($this->store === null) {
            throw new \LogicException('Use BoltTest::make(jobs: true) to test durable intake.');
        }
        foreach ($classes as $class) {
            if (!class_exists($class)) {
                throw new \InvalidArgumentException('The job handler class does not exist.');
            }
            foreach (new \ReflectionClass($class)->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getAttributes(CommandHandler::class) === []) {
                    continue;
                }
                $type = ($method->getParameters()[0] ?? null)?->getType();
                if (!$type instanceof \ReflectionNamedType || !is_subclass_of($type->getName(), Job::class)) {
                    throw new \InvalidArgumentException('A test job handler must accept a named Job.');
                }
                $this->commands->addHandler(
                    new CommandHandler(),
                    $type->getName(),
                    MethodReflector::fromParts($class, $method->getName()),
                );
            }
        }
        return $this;
    }

    public function mention(
        string $text,
        string $user = 'U_TEST',
        string $channel = 'C_TEST',
        ?string $threadTs = null,
        string $team = 'T_TEST',
    ): TestResult {
        return $this->messageEvent('app_mention', $text, $user, $channel, $threadTs, $team);
    }

    public function message(
        string $text,
        string $user = 'U_TEST',
        string $channel = 'C_TEST',
        ?string $threadTs = null,
        string $team = 'T_TEST',
    ): TestResult {
        return $this->messageEvent('message', $text, $user, $channel, $threadTs, $team);
    }

    private function messageEvent(
        string $type,
        string $text,
        string $user,
        string $channel,
        ?string $threadTs,
        string $team,
    ): TestResult {
        return $this->event(
            $type,
            [
                'user' => $user,
                'channel' => $channel,
                'text' => $text,
                'ts' => '1700000000.' . str_pad((string) ($this->deliveries + 1), 6, '0', STR_PAD_LEFT),
                ...($threadTs === null ? [] : ['thread_ts' => $threadTs]),
            ],
            team: $team,
        );
    }

    public function event(string $type, array $data = [], string $team = 'T_TEST', ?string $eventId = null): TestResult
    {
        return $this->send([
            'type' => 'event_callback',
            'team_id' => $team,
            'event_id' => $eventId ?? 'Ev_TEST_' . ($this->deliveries + 1),
            'event' => [...$data, 'type' => $type],
        ])->assertStatus(200);
    }

    public function command(
        string $name,
        string $text = '',
        string $user = 'U_TEST',
        string $channel = 'C_TEST',
        string $team = 'T_TEST',
    ): TestResult {
        return $this->send([
            'command' => $name,
            'text' => $text,
            'user_id' => $user,
            'channel_id' => $channel,
            'team_id' => $team,
            'response_url' => 'https://example.test/response',
            'trigger_id' => 'TRIGGER_TEST',
        ], form: true)->assertStatus(200);
    }

    public function interaction(array|InteractionFixture $payload): TestResult
    {
        $payload = $payload instanceof InteractionFixture ? $payload->toArray() : $payload;
        return $this->send($payload, form: true)->assertStatus(200);
    }

    /** Sends signed bytes through the real HTTP receiver. Use for malformed/negative cases too. */
    public function send(array $payload, bool $form = false): TestResult
    {
        $this->failure = null;
        $this->deliveries++;
        $offset = count($this->slack->calls());
        $before = array_column($this->store?->recent(200) ?? [], 'id');
        $raw = $form
            ? http_build_query(
                isset($payload['command'])
                    ? $payload
                    : [
                        'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                    ],
            )
            : json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $response = $this->receiver->handle(
            $raw,
            [
                'X-Slack-Request-Timestamp' => $timestamp,
                'X-Slack-Signature' =>
                    'v0=' . hash_hmac('sha256', 'v0:' . $timestamp . ':' . $raw, $this->config->signingSecret),
            ],
            $form ? 'application/x-www-form-urlencoded' : 'application/json',
        );
        $records = $this->store?->recent(200) ?? [];
        if (count($records) === 200) {
            throw new \LengthException(
                'Start a new test harness before accepting 200 jobs; assertions must not truncate intake.',
            );
        }
        $event = is_array($payload['event'] ?? null) ? $payload['event'] : $payload;
        $channel = $event['channel'] ?? $event['channel_id'] ?? null;
        $thread = $event['thread_ts'] ?? $event['ts'] ?? null;
        $user = $event['user'] ?? $event['user_id'] ?? null;
        if ($response->status === 200 && \Thunder\Bolt\Invocation\Interaction::kind($payload) !== null) {
            $interaction = \Thunder\Bolt\Invocation\Interaction::fromArray($payload);
            if (
                $interaction instanceof \Thunder\Bolt\Payloads\Action
                || $interaction instanceof \Thunder\Bolt\Payloads\Shortcut
            ) {
                $channel = $interaction->channelId;
                $thread = $interaction->threadTs ?? $interaction->messageTs;
                $user = $interaction->userId;
            } else {
                $channel = $thread = $user = null;
            }
        }
        return new TestResult(
            $response,
            array_slice($this->slack->calls(), $offset),
            array_values(array_filter($records, static fn($job): bool => !in_array($job->id, $before, true))),
            is_string($channel) ? $channel : null,
            is_string($thread) ? $thread : null,
            is_string($user) ? $user : null,
            $this->failure,
        );
    }
}
