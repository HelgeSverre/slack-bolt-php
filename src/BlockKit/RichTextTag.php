<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class RichTextTag extends RichTextLeaf
{
    private function __construct(
        private string $text,
        private ?RichTextTagColor $tagColor,
        private RichTextStyle $textStyle,
    ) {}

    public static function make(string $text): self
    {
        if ($text === '') {
            throw new \InvalidArgumentException('A rich-text tag needs display text.');
        }

        return new self($text, null, RichTextStyle::none());
    }

    #[\NoDiscard]
    public function color(RichTextTagColor $color): self
    {
        return clone($this, ['tagColor' => $color]);
    }

    #[\NoDiscard]
    public function gray(): self
    {
        return $this->color(RichTextTagColor::Gray);
    }

    #[\NoDiscard]
    public function brown(): self
    {
        return $this->color(RichTextTagColor::Brown);
    }

    #[\NoDiscard]
    public function purple(): self
    {
        return $this->color(RichTextTagColor::Purple);
    }

    #[\NoDiscard]
    public function indigo(): self
    {
        return $this->color(RichTextTagColor::Indigo);
    }

    #[\NoDiscard]
    public function blue(): self
    {
        return $this->color(RichTextTagColor::Blue);
    }

    #[\NoDiscard]
    public function green(): self
    {
        return $this->color(RichTextTagColor::Green);
    }

    #[\NoDiscard]
    public function yellow(): self
    {
        return $this->color(RichTextTagColor::Yellow);
    }

    #[\NoDiscard]
    public function orange(): self
    {
        return $this->color(RichTextTagColor::Orange);
    }

    #[\NoDiscard]
    public function red(): self
    {
        return $this->color(RichTextTagColor::Red);
    }

    #[\NoDiscard]
    public function highlight(): self
    {
        return $this->withHighlight();
    }

    #[\NoDiscard]
    public function clientHighlight(): self
    {
        return $this->withClientHighlight();
    }

    #[\NoDiscard]
    public function unlink(): self
    {
        return $this->withUnlink();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = ['type' => 'tag', 'text' => $this->text];
        if ($this->tagColor !== null) {
            $data['color'] = $this->tagColor->value;
        }

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

    protected function supportsCode(): bool
    {
        return false;
    }
}
