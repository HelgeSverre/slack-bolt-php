<?php

declare(strict_types=1);

namespace Thunder\Bolt\Assistant;

final readonly class Status
{
    private function __construct(
        private string $text,
        private array $options = [],
    ) {}

    public static function make(string $text): self
    {
        if (mb_strlen($text) > 1000) {
            throw new \InvalidArgumentException('Assistant status must be at most 1000 characters.');
        }
        return new self($text);
    }

    #[\NoDiscard]
    public function loadingMessages(string ...$messages): self
    {
        if (count($messages) > 10 || array_any($messages, static fn(string $text): bool => trim($text) === '')) {
            throw new \InvalidArgumentException('Supply up to ten non-empty loading messages.');
        }
        return clone($this, ['options' => [...$this->options, 'loading_messages' => array_values($messages)]]);
    }

    #[\NoDiscard]
    public function icon(string $emojiOrUrl): self
    {
        if (filter_var($emojiOrUrl, FILTER_VALIDATE_URL)) {
            $scheme = parse_url($emojiOrUrl, PHP_URL_SCHEME);
            if (!in_array($scheme, ['https', 'http'], true)) {
                throw new \InvalidArgumentException('Status icons need an HTTP(S) URL.');
            }
            $key = 'icon_url';
        } else {
            if (!preg_match('/\A:[a-zA-Z0-9_+\-]+:\z/', $emojiOrUrl)) {
                throw new \InvalidArgumentException('Status icons need an emoji shortcode or URL.');
            }
            $key = 'icon_emoji';
        }
        $options = $this->options;
        unset($options['icon_url'], $options['icon_emoji']);
        return clone($this, ['options' => [...$options, $key => $emojiOrUrl]]);
    }

    #[\NoDiscard]
    public function username(string $name): self
    {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('A display name cannot be empty.');
        }
        return clone($this, ['options' => [...$this->options, 'username' => $name]]);
    }

    public function toArray(): array
    {
        $options = $this->options;
        if (($options['loading_messages'] ?? null) === []) {
            unset($options['loading_messages']);
        }
        return ['status' => $this->text, ...$options];
    }
}
