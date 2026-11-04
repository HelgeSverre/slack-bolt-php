<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class RichTextMessageMention extends RichTextLeaf
{
    private function __construct(
        private string $channelId,
        private string $messageTimestamp,
        private ?string $authorId,
        private ?string $threadTimestamp,
        private ?string $text,
        private ?string $url,
        private RichTextStyle $textStyle,
    ) {}

    public static function for(string $channelId, string $messageTimestamp): self
    {
        self::required($channelId, 'channel ID');
        self::required($messageTimestamp, 'message timestamp');

        return new self($channelId, $messageTimestamp, null, null, null, null, RichTextStyle::none());
    }

    #[\NoDiscard]
    public function author(string $authorId): self
    {
        self::required($authorId, 'author ID');

        return clone($this, ['authorId' => $authorId]);
    }

    #[\NoDiscard]
    public function thread(string $threadTimestamp): self
    {
        self::required($threadTimestamp, 'thread timestamp');

        return clone($this, ['threadTimestamp' => $threadTimestamp]);
    }

    #[\NoDiscard]
    public function text(string $text): self
    {
        self::required($text, 'display text');

        return clone($this, ['text' => $text]);
    }

    #[\NoDiscard]
    public function url(string $url): self
    {
        self::urlValue($url, 'message');

        return clone($this, ['url' => $url]);
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
        $data = [
            'type' => 'message_mention',
            'channel_id' => $this->channelId,
            'message_ts' => $this->messageTimestamp,
        ];
        if ($this->authorId !== null) {
            $data['author_id'] = $this->authorId;
        }
        if ($this->threadTimestamp !== null) {
            $data['thread_ts'] = $this->threadTimestamp;
        }
        if ($this->text !== null) {
            $data['text'] = $this->text;
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

    protected function supportsCode(): bool
    {
        return false;
    }

    private static function required(string $value, string $field): void
    {
        if ($value === '') {
            throw new \InvalidArgumentException("A rich-text message mention needs a {$field}.");
        }
    }

    private static function urlValue(string $url, string $kind): void
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException("A rich-text {$kind} URL needs to be absolute.");
        }
    }
}
