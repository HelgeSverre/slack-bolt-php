<?php

declare(strict_types=1);

namespace Thunder\Bolt\Assistant;

use Thunder\Bolt\Assistant\Streaming\Chunk;
use Thunder\Bolt\Assistant\Streaming\MarkdownText;
use Thunder\Bolt\BlockKit\Block;
use Thunder\Bolt\WebApi\Client;
use Thunder\Bolt\WebApi\Response;

/** A single, thread-bound stream. Failed requests are never replayed automatically. */
final class MessageStream
{
    private ?string $timestamp = null;
    private bool $closed = false;
    private bool $failed = false;
    private int $textLength = 0;
    /** @var array<string|int, true> */
    private array $taskIds = [];

    public function __construct(
        private readonly Client $client,
        private readonly ThreadIdentity $identity,
        private readonly array $options = [],
    ) {
        $allowed = ['task_display_mode', 'icon_emoji', 'icon_url', 'username', 'recipient_team_id'];
        if (array_diff(array_keys($options), $allowed) !== []) {
            throw new \InvalidArgumentException(
                'Unsupported stream options. Destination and credentials come from the thread.',
            );
        }
        if (
            isset($options['task_display_mode']) && !in_array($options['task_display_mode'], ['timeline', 'plan'], true)
        ) {
            throw new \InvalidArgumentException('Stream tasks use timeline or plan display.');
        }
    }

    public function append(string $markdown): self
    {
        // Slack fixes a stream's wire mode on its first request. Always use chunks
        // so text and task progress can be mixed throughout the same stream.
        return $this->chunks([MarkdownText::make($markdown)]);
    }

    /** @param list<Chunk|array<string, mixed>> $chunks */
    public function chunks(array $chunks): self
    {
        $this->assertOpen();
        if ($chunks === [] || !array_is_list($chunks)) {
            throw new \InvalidArgumentException('Stream chunks must be a non-empty list.');
        }
        $wire = [];
        $length = $this->textLength;
        $tasks = $this->taskIds;
        foreach ($chunks as $chunk) {
            $chunk = $chunk instanceof Chunk ? $chunk->toArray() : $chunk;
            if (!is_array($chunk) || !is_string($chunk['type'] ?? null)) {
                throw new \InvalidArgumentException('Each stream chunk needs a type.');
            }
            if ($chunk['type'] === 'markdown_text' && is_string($chunk['text'] ?? null)) {
                $length += mb_strlen($chunk['text']);
            }
            if ($chunk['type'] === 'task_update' && is_string($chunk['id'] ?? null)) {
                $tasks[$chunk['id']] = true;
            }
            $wire[] = $chunk;
        }
        if ($length > 12000 || count($tasks) > 50) {
            throw new \LengthException('A stream supports at most 12000 text characters and 50 unique tasks.');
        }
        $this->send(['chunks' => $wire]);
        $this->textLength = $length;
        $this->taskIds = $tasks;
        return $this;
    }

    public function stop(Block ...$blocks): Response
    {
        return $this->finish(AgentSessionStatus::Active, ...$blocks);
    }

    public function finish(AgentSessionStatus $status = AgentSessionStatus::Active, Block ...$blocks): Response
    {
        $this->assertOpen();
        if (count($blocks) > 50) {
            throw new \LengthException('Stream completion supports at most 50 final blocks.');
        }
        if ($this->timestamp === null) {
            throw new \LogicException('Append text or chunks before stopping a stream.');
        }
        try {
            $response = $this->client
                ->chat()
                ->stopStream([
                    'channel' => $this->identity->channelId(),
                    'ts' => $this->timestamp,
                    'session_status' => $status->value,
                    ...(
                        $blocks !== []
                            ? ['blocks' => array_map(
                                static fn(Block $block): array => $block->toArray(),
                                array_values($blocks),
                            )]
                            : []
                    ),
                ]);
            $this->closed = true;
            return $response;
        } catch (\Throwable $exception) {
            $this->failed = true;
            throw $exception;
        }
    }

    public function timestamp(): ?string
    {
        return $this->timestamp;
    }

    private function send(array $content): void
    {
        $this->assertOpen();
        try {
            if ($this->timestamp !== null) {
                $this->client
                    ->chat()
                    ->appendStream([
                        'channel' => $this->identity->channelId(),
                        'ts' => $this->timestamp,
                        ...$content,
                    ]);
                return;
            }
            $team = $this->identity->key->teamId;
            $response = $this->client
                ->chat()
                ->startStream([
                    ...$this->options,
                    'channel' => $this->identity->channelId(),
                    'thread_ts' => $this->identity->threadTs(),
                    'recipient_user_id' => $this->identity->userId,
                    ...($team !== null ? ['recipient_team_id' => $team] : []),
                    ...$content,
                ]);
            $timestamp = $response->get('ts');
            if (!is_string($timestamp) || $timestamp === '') {
                throw new \UnexpectedValueException('Slack did not return a stream timestamp.');
            }
            $this->timestamp = $timestamp;
        } catch (\Throwable $exception) {
            $this->failed = true;
            throw $exception;
        }
    }

    private function assertOpen(): void
    {
        if ($this->closed || $this->failed) {
            throw new \LogicException('A closed or failed stream cannot be reused.');
        }
    }
}
