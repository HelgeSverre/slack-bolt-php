<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class RichTextBroadcastMention extends RichTextLeaf
{
    private function __construct(
        private string $range,
        private RichTextStyle $textStyle,
    ) {}

    public static function here(): self
    {
        return new self('here', RichTextStyle::none());
    }

    public static function channel(): self
    {
        return new self('channel', RichTextStyle::none());
    }

    public static function everyone(): self
    {
        return new self('everyone', RichTextStyle::none());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = ['type' => 'broadcast', 'range' => $this->range];
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
}
