<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class RichTextDate extends RichTextLeaf
{
    private function __construct(
        private int $timestamp,
        private string $format,
        private ?string $url,
        private ?string $fallback,
        private RichTextStyle $textStyle,
    ) {}

    public static function at(int $timestamp, string $format): self
    {
        if ($timestamp < 0) {
            throw new \InvalidArgumentException('A rich-text date timestamp cannot be negative.');
        }
        if ($format === '') {
            throw new \InvalidArgumentException('A rich-text date format is required.');
        }

        return new self($timestamp, $format, null, null, RichTextStyle::none());
    }

    #[\NoDiscard]
    public function url(string $url): self
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('A rich-text date URL needs to be absolute.');
        }

        return clone($this, ['url' => $url]);
    }

    #[\NoDiscard]
    public function fallback(string $fallback): self
    {
        if ($fallback === '') {
            throw new \InvalidArgumentException('A rich-text date fallback cannot be empty.');
        }

        return clone($this, ['fallback' => $fallback]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = ['type' => 'date', 'timestamp' => $this->timestamp, 'format' => $this->format];
        if ($this->url !== null) {
            $data['url'] = $this->url;
        }
        if ($this->fallback !== null) {
            $data['fallback'] = $this->fallback;
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
