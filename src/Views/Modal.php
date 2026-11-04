<?php

declare(strict_types=1);

namespace Thunder\Bolt\Views;

use Thunder\Bolt\BlockKit\Block;
use Thunder\Bolt\BlockKit\Internal\BlockRules;
use Thunder\Bolt\BlockKit\Text;

final readonly class Modal implements View
{
    /** @param list<Block> $blocks */
    private function __construct(
        private Text $title,
        private array $blocks,
        private ?Text $submitLabel = null,
        private ?Text $closeLabel = null,
        private ?string $callback = null,
        private ?string $metadata = null,
        private ?bool $clear = null,
        private ?bool $notify = null,
        private ?string $external = null,
    ) {}

    public static function make(string|Text $title, Block ...$blocks): self
    {
        if (count($blocks) > 100) {
            throw new \InvalidArgumentException('A modal accepts at most 100 blocks.');
        }
        return new self(self::label($title), array_values($blocks));
    }

    #[\NoDiscard]
    public function submit(string|Text $text): self
    {
        return clone($this, ['submitLabel' => self::label($text)]);
    }

    #[\NoDiscard]
    public function close(string|Text $text): self
    {
        return clone($this, ['closeLabel' => self::label($text)]);
    }

    #[\NoDiscard]
    public function callbackId(string $id): self
    {
        self::length($id, 255);
        return clone($this, ['callback' => $id]);
    }

    #[\NoDiscard]
    public function privateMetadata(string $metadata): self
    {
        self::length($metadata, 3000);
        return clone($this, ['metadata' => $metadata]);
    }

    #[\NoDiscard]
    public function externalId(string $id): self
    {
        self::length($id, 255);
        return clone($this, ['external' => $id]);
    }

    #[\NoDiscard]
    public function clearOnClose(bool $enabled = true): self
    {
        return clone($this, ['clear' => $enabled]);
    }

    #[\NoDiscard]
    public function notifyOnClose(bool $enabled = true): self
    {
        return clone($this, ['notify' => $enabled]);
    }

    public function toArray(): array
    {
        $blocks = array_map(static fn(Block $block) => $block->toArray(), $this->blocks);
        $flat = BlockRules::flatten($blocks);
        if (count($flat) > 100) {
            throw new \InvalidArgumentException('A modal accepts at most 100 blocks including nested blocks.');
        }
        foreach ($flat as $block) {
            if (in_array($block['type'] ?? null, ['container', 'data_table', 'data_visualization'], true)) {
                throw new \InvalidArgumentException(
                    'Container, data table and data visualization blocks are not supported in modals.',
                );
            }
        }
        if ($this->submitLabel === null) {
            foreach ($flat as $block) {
                if (($block['type'] ?? null) === 'input') {
                    throw new \LogicException('A modal containing input blocks requires a submit label.');
                }
            }
        }
        return array_filter(
            [
                'type' => 'modal',
                'title' => $this->title->toArray(),
                'blocks' => $blocks,
                'submit' => $this->submitLabel?->toArray(),
                'close' => $this->closeLabel?->toArray(),
                'callback_id' => $this->callback,
                'private_metadata' => $this->metadata,
                'clear_on_close' => $this->clear,
                'notify_on_close' => $this->notify,
                'external_id' => $this->external,
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function label(string|Text $text): Text
    {
        $text = is_string($text) ? Text::plain($text) : $text;
        if ($text->type !== 'plain_text' || mb_strlen($text->value) > 24) {
            throw new \InvalidArgumentException(
                'Modal title and button labels require plain text of at most 24 characters.',
            );
        }
        return $text;
    }

    private static function length(string $value, int $limit): void
    {
        if (mb_strlen($value) > $limit) {
            throw new \InvalidArgumentException("View metadata exceeds {$limit} characters.");
        }
    }
}
