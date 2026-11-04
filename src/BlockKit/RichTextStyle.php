<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

/** Immutable inline formatting shared by Slack rich-text leaf elements. */
final readonly class RichTextStyle
{
    private function __construct(
        private bool $bold = false,
        private bool $code = false,
        private bool $italic = false,
        private bool $strike = false,
        private bool $underline = false,
        private bool $highlight = false,
        private bool $clientHighlight = false,
        private bool $unlink = false,
    ) {}

    public static function none(): self
    {
        return new self();
    }

    #[\NoDiscard]
    public function bold(): self
    {
        return clone($this, ['bold' => true]);
    }

    #[\NoDiscard]
    public function code(): self
    {
        return clone($this, ['code' => true]);
    }

    #[\NoDiscard]
    public function italic(): self
    {
        return clone($this, ['italic' => true]);
    }

    #[\NoDiscard]
    public function strike(): self
    {
        return clone($this, ['strike' => true]);
    }

    #[\NoDiscard]
    public function underline(): self
    {
        return clone($this, ['underline' => true]);
    }

    #[\NoDiscard]
    public function highlight(): self
    {
        return clone($this, ['highlight' => true]);
    }

    #[\NoDiscard]
    public function clientHighlight(): self
    {
        return clone($this, ['clientHighlight' => true]);
    }

    #[\NoDiscard]
    public function unlink(): self
    {
        return clone($this, ['unlink' => true]);
    }

    /** @return array<string, true> */
    public function toArray(): array
    {
        return array_filter([
            'bold' => $this->bold,
            'code' => $this->code,
            'italic' => $this->italic,
            'strike' => $this->strike,
            'underline' => $this->underline,
            'highlight' => $this->highlight,
            'client_highlight' => $this->clientHighlight,
            'unlink' => $this->unlink,
        ]);
    }
}
