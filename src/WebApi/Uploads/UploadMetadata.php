<?php

declare(strict_types=1);

namespace Thunder\Bolt\WebApi\Uploads;

use Thunder\Bolt\BlockKit\Block;

/** Immutable Slack metadata and destination, reusable across a group of uploads. */
final readonly class UploadMetadata
{
    /** @param array<string, mixed> $values */
    private function __construct(
        private array $values,
    ) {}

    #[\NoDiscard]
    public static function make(): self
    {
        return new self([]);
    }

    #[\NoDiscard]
    public function title(string $title): self
    {
        return $this->with('title', $title);
    }

    #[\NoDiscard]
    public function altText(string $altText): self
    {
        return $this->with('alt_text', $altText);
    }

    #[\NoDiscard]
    public function snippetType(string $snippetType): self
    {
        return $this->with('snippet_type', $snippetType);
    }

    #[\NoDiscard]
    public function highlightType(string $highlightType): self
    {
        return $this->with('highlight_type', $highlightType);
    }

    #[\NoDiscard]
    public function toChannel(string $channelId): self
    {
        if ($channelId === '') {
            throw new \InvalidArgumentException('An upload channel ID must not be empty.');
        }

        return $this->with('channel_id', $channelId);
    }

    #[\NoDiscard]
    public function inThread(string $threadTs): self
    {
        if (!is_string($this->values['channel_id'] ?? null)) {
            throw new \LogicException('Choose an upload channel before choosing a thread.');
        }
        if (preg_match('/^[0-9]+\\.[0-9]{6}$/', $threadTs) !== 1) {
            throw new \InvalidArgumentException('An upload thread timestamp must be a Slack timestamp.');
        }

        return $this->with('thread_ts', $threadTs);
    }

    #[\NoDiscard]
    public function comment(string $comment): self
    {
        return $this->with('initial_comment', $comment);
    }

    #[\NoDiscard]
    public function blocks(Block ...$blocks): self
    {
        if ($blocks === []) {
            throw new \InvalidArgumentException('An upload block message needs at least one block.');
        }
        if (count($blocks) > 50) {
            throw new \InvalidArgumentException('An upload block message supports at most 50 blocks.');
        }

        return $this->with(
            'blocks',
            array_values(array_map(static fn(Block $block): array => $block->toArray(), $blocks)),
        );
    }

    /** @internal */
    public function text(string $key): ?string
    {
        return is_string($this->values[$key] ?? null) ? $this->values[$key] : null;
    }

    /** @return array<string, mixed> */
    public function completionDestination(): array
    {
        return array_filter(
            [
                'channel_id' => $this->values['channel_id'] ?? null,
                'thread_ts' => $this->values['thread_ts'] ?? null,
                'initial_comment' => $this->values['initial_comment'] ?? null,
                'blocks' => $this->values['blocks'] ?? null,
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }

    private function with(string $key, mixed $value): self
    {
        return new self([...$this->values, $key => $value]);
    }
}
