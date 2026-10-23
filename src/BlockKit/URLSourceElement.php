<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

/** A typed URL source used by Slack task-card blocks. */
final readonly class URLSourceElement implements \JsonSerializable
{
    private function __construct(
        private string $url,
        private string $text,
    ) {}

    public static function make(string $url, string $text): self
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('A URL source needs a valid absolute URL.');
        }
        if ($text === '') {
            throw new \InvalidArgumentException('A URL source needs display text.');
        }

        return new self($url, $text);
    }

    /** @return array{type: 'url', url: string, text: string} */
    public function toArray(): array
    {
        return ['type' => 'url', 'url' => $this->url, 'text' => $this->text];
    }

    /** @return array{type: 'url', url: string, text: string} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
