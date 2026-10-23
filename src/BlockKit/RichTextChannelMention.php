<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class RichTextChannelMention extends RichTextLeaf
{
    private function __construct(
        private string $channelId,
        private RichTextStyle $textStyle,
    ) {}

    public static function for(string $channelId): self
    {
        self::id($channelId, 'channel');

        return new self($channelId, RichTextStyle::none());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = ['type' => 'channel', 'channel_id' => $this->channelId];
        $this->addStyle($data, $this->textStyle);

        return $data;
    }

    protected function currentStyle(): RichTextStyle
    {
        return $this->textStyle;
    }

    protected function withStyle(RichTextStyle $style): static
    {
        return clone($this, ['textStyle' => $style]);
    }

    private static function id(string $value, string $kind): void
    {
        if ($value === '') {
            throw new \InvalidArgumentException("A rich-text {$kind} ID is required.");
        }
    }
}
