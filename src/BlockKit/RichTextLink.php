<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class RichTextLink extends RichTextLeaf
{
    private function __construct(
        private string $url,
        private ?string $text,
        private bool $isUnsafe,
        private RichTextStyle $textStyle,
    ) {}

    public static function to(string $url, ?string $text = null): self
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('A rich-text link needs a valid absolute URL.');
        }
        if ($text === '') {
            throw new \InvalidArgumentException('A rich-text link label cannot be empty.');
        }

        return new self($url, $text, false, RichTextStyle::none());
    }

    #[\NoDiscard]
    public function unsafe(): self
    {
        return clone($this, ['isUnsafe' => true]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = ['type' => 'link', 'url' => $this->url];
        if ($this->text !== null) {
            $data['text'] = $this->text;
        }
        if ($this->isUnsafe) {
            $data['unsafe'] = true;
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
}
