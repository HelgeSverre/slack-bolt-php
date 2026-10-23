<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class RichTextEmoji extends RichTextLeaf
{
    private function __construct(
        private string $name,
        private ?string $unicode,
        private ?string $url,
        private RichTextStyle $textStyle,
    ) {}

    public static function named(string $name): self
    {
        if ($name === '') {
            throw new \InvalidArgumentException('A rich-text emoji needs a name.');
        }

        return new self($name, null, null, RichTextStyle::none());
    }

    #[\NoDiscard]
    public function unicode(string $unicode): self
    {
        if (!preg_match('/^[0-9a-f]+$/', $unicode)) {
            throw new \InvalidArgumentException('Emoji Unicode must be lowercase hexadecimal.');
        }

        return clone($this, ['unicode' => $unicode]);
    }

    #[\NoDiscard]
    public function url(string $url): self
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('An emoji asset needs a valid absolute URL.');
        }

        return clone($this, ['url' => $url]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = ['type' => 'emoji', 'name' => $this->name];
        if ($this->unicode !== null) {
            $data['unicode'] = $this->unicode;
        }
        if ($this->url !== null) {
            $data['url'] = $this->url;
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
