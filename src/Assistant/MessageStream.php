<?php

declare(strict_types=1);

namespace Thunder\Bolt\Assistant;

use Thunder\Bolt\BlockKit\Block;
use Thunder\Bolt\WebApi\Client;
use Thunder\Bolt\WebApi\Response;

/** A single, thread-bound stream. Failed requests are never replayed automatically. */
final class MessageStream
{
    private ?string $timestamp = null;
    private bool $closed = false;
    private bool $failed = false;

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
        if ($markdown === '' || mb_strlen($markdown) > 12000) {
            throw new \InvalidArgumentException('A stream fragment must contain 1 to 12000 characters.');
        }
        $this->send(['markdown_text' => $markdown]);
        return $this;
    }

    /** @param list<array<string, mixed>> $chunks */
    public function chunks(array $chunks): self
    {
        if ($chunks === [] || !array_is_list($chunks)) {
            throw new \InvalidArgumentException('Stream chunks must be a non-empty list.');
        }
        foreach ($chunks as $chunk) {
            if (!is_array($chunk) || !is_string($chunk['type'] ?? null)) {
                throw new \InvalidArgumentException('Each stream chunk needs a type.');
            }
        }
        $this->send(['chunks' => $chunks]);
        return $this;
    }

    public function stop(Block ...$blocks): Response
    {
        $this->assertOpen();
        if ($this->timestamp === null) {
            throw new \LogicException('Append text or chunks before stopping a stream.');
        }
        try {
            $response = $this->client
                ->chat()
                ->stopStream([
                    'channel' => $this->identity->channelId(),
                    'ts' => $this->timestamp,
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
