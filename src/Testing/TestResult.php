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
            $mismatch = self::subsetMismatch($expected, $body, '$');
            if ($mismatch !== null) {
                self::expect(false, 'The acknowledgement did not contain the expected fields: ' . $mismatch);
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

    /**
     * Asserts a strict recursive subset of one or more captured Slack calls.
     * Empty expected arrays require an exactly empty array at that path. At
     * the top level, [] therefore means a call with no arguments.
     *
     * @param array<string, mixed> $expected
     */
    public function assertApiCalledWith(string $method, array $expected, ?int $times = null): self
    {
        $calls = array_values(array_filter(
            $this->calls,
            static fn(SlackCall $call): bool => $call->method === $method,
        ));
        $matches = [];
        $mismatch = null;
        foreach ($calls as $call) {
            $candidate = self::subsetMismatch($expected, $call->arguments, '$');
            if ($candidate === null) {
                $matches[] = $call;
                continue;
            }
            $mismatch ??= $candidate;
        }
        $count = count($matches);
        $satisfied = $times === null ? $count > 0 : $count === $times;
        $detail = $calls === []
            ? "No {$method} calls were captured."
            : $mismatch ?? 'No call matched the expected payload.';
        self::expect($satisfied, "Expected matching Slack calls for {$method}. Found {$count}. {$detail}");

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
            [
                'chat.postMessage',
                'chat.postEphemeral',
                'chat.update',
                'chat.delete',
                'chat.startStream',
                'chat.appendStream',
                'chat.stopStream',
                'response_url',
            ],
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

    private static function subsetMismatch(mixed $expected, mixed $actual, string $path): ?string
    {
        if (is_array($expected)) {
            if (!is_array($actual)) {
                return "{$path} expected an array but received " . self::describe($actual, $path) . '.';
            }
            if ($expected === [] && $actual !== []) {
                return "{$path} expected an empty array but received " . self::describe($actual, $path) . '.';
            }
            foreach ($expected as $key => $value) {
                $child = self::path($path, $key);
                if (!array_key_exists($key, $actual)) {
                    return "{$child} was missing.";
                }
                $mismatch = self::subsetMismatch($value, $actual[$key], $child);
                if ($mismatch !== null) {
                    return $mismatch;
                }
            }

            return null;
        }

        if ($expected === $actual) {
            return null;
        }

        return (
            "{$path} expected "
            . self::describe($expected, $path)
            . ' but received '
            . self::describe($actual, $path)
            . '.'
        );
    }

    private static function path(string $parent, int|string $key): string
    {
        return is_int($key) ? "{$parent}[{$key}]" : "{$parent}.{$key}";
    }

    private static function describe(mixed $value, string $path): string
    {
        if (preg_match('/(?:token|secret|authorization|response_url|password|code)(?:$|[.\[])/i', $path) === 1) {
            return '[redacted]';
        }
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => get_debug_type($value) . "({$value})",
            is_string($value) => "string('" . substr($value, 0, 80) . (strlen($value) > 80 ? '…' : '') . "')",
            is_array($value) => 'array(' . count($value) . ')',
            default => get_debug_type($value),
        };
    }
}
