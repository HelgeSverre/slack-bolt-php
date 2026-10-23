<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class RichTextColor extends RichTextLeaf
{
    private function __construct(
        private string $value,
        private RichTextStyle $textStyle,
    ) {}

    public static function hex(string $value): self
    {
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
            throw new \InvalidArgumentException('A rich-text color needs a six-digit hexadecimal value.');
        }

        return new self($value, RichTextStyle::none());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = ['type' => 'color', 'value' => $this->value];
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
