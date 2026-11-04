<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class Text implements \JsonSerializable
{
    private function __construct(
        public string $type,
        public string $value,
        private bool $emoji = true,
        private ?bool $verbatim = null,
    ) {}

    public static function plain(string $text, bool $emoji = true): self
    {
        self::text($text, 3000);
        return new self('plain_text', $text, $emoji);
    }

    public static function markdown(string $text, bool $verbatim = false): self
    {
        self::text($text, 3000);
        return new self('mrkdwn', $text, true, $verbatim);
    }

    /** @return array<string,mixed> */ public function toArray(): array
    {
        $data = ['type' => $this->type, 'text' => $this->value];
        // Explicit defaults also work in Slack's nested streaming block parser.
        if ($this->type === 'plain_text')
            $data['emoji'] = $this->emoji;
        if ($this->type === 'mrkdwn' && $this->verbatim)
            $data['verbatim'] = true;
        return $data;
    }

    /** @return array<string,mixed> */ public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function text(string $text, int $max): void
    {
        if ($text === '' || mb_strlen($text) > $max)
            throw new \InvalidArgumentException("Text needs between 1 and {$max} characters.");
    }
}
