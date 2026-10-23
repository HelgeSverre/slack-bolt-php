<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class RichTextText extends RichTextLeaf
{
    private function __construct(
        private string $text,
        private RichTextStyle $textStyle,
    ) {}

    public static function make(string $text): self
    {
        if ($text === '') {
            throw new \InvalidArgumentException('Rich text cannot be empty.');
        }

        return new self($text, RichTextStyle::none());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = ['type' => 'text', 'text' => $this->text];
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
