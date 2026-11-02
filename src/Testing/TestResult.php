<?php

declare(strict_types=1);

namespace Thunder\Bolt\Testing;

use Thunder\Bolt\Http\Response;
use Thunder\Bolt\Jobs\JobRecord;

/** Assertions cover only calls and new jobs from this delivery, not previous deliveries. */
final readonly class TestResult
{
    /**
     * @param list<SlackCall> $calls
     * @param list<JobRecord> $jobs
     */
    public function __construct(
        public Response $response,
        private array $calls,
        private array $jobs,
        private ?string $channel,
        private ?string $thread,
        private ?string $user,
        public ?\Throwable $error = null,
    ) {}

    /** @return list<SlackCall> */
    public function calls(): array
    {
        return $this->calls;
    }

    /** @return list<JobRecord> */
    public function jobs(): array
    {
        return $this->jobs;
    }

    public function assertStatus(int $status): self
    {
        self::expect(
            $this->response->status === $status,
            "Expected HTTP {$status}; received {$this->response->status}.",
            $this->error,
        );
        return $this;
    }

    public function assertAcknowledged(array $expected = []): self
    {
        $this->assertStatus(200);
        if ($expected !== []) {
            $body = json_decode($this->response->body, true);
            foreach ($expected as $key => $value) {
                self::expect(
                    is_array($body) && array_key_exists($key, $body) && $body[$key] === $value,
                    'The acknowledgement did not contain the expected field: ' . $key,
                );
            }
        }
        return $this;
    }

    /** @param null|callable(SlackCall): bool $predicate */
    public function assertApiCalled(string $method, ?callable $predicate = null, ?int $times = null): self
    {
        $matches = array_filter(
            $this->calls,
            static fn(SlackCall $call): bool => $call->method === $method && ($predicate === null || $predicate($call)),
        );
        self::expect(
            $times === null ? count($matches) > 0 : count($matches) === $times,
            "Expected matching Slack calls for {$method}. Found " . count($matches) . '.',
        );
        return $this;
    }

    public function assertMessageSent(string $text, ?string $channel = null): self
    {
        return $this->assertApiCalled(
            'chat.postMessage',
            static fn(SlackCall $call): bool => (
                ($call->arguments['text'] ?? null) === $text
                && ($channel === null || ($call->arguments['channel'] ?? null) === $channel)
            ),
        );
    }

    public function assertThreadReply(string $text): self
    {
        self::expect(
            $this->thread !== null && $this->channel !== null,
            'This fixture has no known thread destination.',
        );
        return $this->assertApiCalled(
            'chat.postMessage',
            fn(SlackCall $call): bool => (
                ($call->arguments['text'] ?? null) === $text
                && ($call->arguments['channel'] ?? null) === $this->channel
                && ($call->arguments['thread_ts'] ?? null) === $this->thread
            ),
        );
    }

    public function assertEphemeralReply(string $text): self
    {
        return $this->assertApiCalled(
            'chat.postEphemeral',
            fn(SlackCall $call): bool => (
                ($call->arguments['text'] ?? null) === $text
                && ($call->arguments['channel'] ?? null) === $this->channel
                && ($call->arguments['user'] ?? null) === $this->user
            ),
        );
    }

    public function assertNoMessagesSent(): self
    {
        self::expect(!array_any($this->calls, static fn(SlackCall $call): bool => in_array(
            $call->method,
            ['chat.postMessage', 'chat.postEphemeral', 'chat.update', 'chat.delete', 'response_url'],
            true,
        )), 'Unexpected Slack message calls were recorded.');
        return $this;
    }

    public function assertNoApiCalls(): self
    {
        self::expect($this->calls === [], 'Unexpected Slack calls were recorded.');
        return $this;
    }

    /**
     * @param class-string $type
     * @param null|callable(JobRecord): bool $predicate
     */
    public function assertJobQueued(string $type, ?callable $predicate = null): self
    {
        self::expect(
            array_any(
                $this->jobs,
                static fn(JobRecord $job): bool => $job->type === $type && ($predicate === null || $predicate($job)),
            ),
            'No matching job was queued for ' . $type . '.',
        );
        return $this;
    }

    public function assertNoJobsQueued(): self
    {
        self::expect($this->jobs === [], 'Unexpected jobs were queued.');
        return $this;
    }

    private static function expect(bool $condition, string $message, ?\Throwable $previous = null): void
    {
        if (!$condition) {
            throw new AssertionFailed($message, previous: $previous);
        }
        if (class_exists(\PHPUnit\Framework\Assert::class, false)) {
            // Count assertions under PHPUnit/Pest without making them runtime dependencies.
            \PHPUnit\Framework\Assert::assertTrue(true);
        }
    }
}
