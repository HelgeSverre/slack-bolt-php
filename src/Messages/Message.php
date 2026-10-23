<?php

declare(strict_types=1);

namespace Thunder\Bolt\Messages;

use Thunder\Bolt\BlockKit\Block;

final readonly class Message implements \JsonSerializable
{
    /** @param list<Block> $contentBlocks @param list<Attachment> $messageAttachments */
    private function __construct(
        private ?string $textContent = null,
        private ?string $markdownContent = null,
        private array $contentBlocks = [],
        private array $messageAttachments = [],
        private ?string $threadTs = null,
        private ?bool $broadcastReply = null,
        private ?MessageMetadata $messageMetadata = null,
        private ?bool $unfurlTextLinks = null,
        private ?bool $unfurlMediaContent = null,
        private ?Parse $textParse = null,
        private ?bool $linkChannelAndUserNames = null,
        private ?bool $useMarkdown = null,
        private ?string $botUsername = null,
        private ?string $botIconEmoji = null,
        private ?string $botIconUrl = null,
        private ?bool $actAsUser = null,
    ) {}

    public static function text(string $text): self
    {
        self::validateText($text);

        return new self(textContent: $text);
    }

    public static function markdown(string $markdown): self
    {
        if ($markdown === '' || mb_strlen($markdown) > 12000) {
            throw new \InvalidArgumentException('Markdown message text needs between 1 and 12000 characters.');
        }

        return new self(markdownContent: $markdown);
    }

    public static function blocks(Block ...$blocks): self
    {
        self::validateBlocks($blocks);

        return new self(contentBlocks: array_values($blocks));
    }

    public static function attachments(Attachment ...$attachments): self
    {
        if ($attachments === []) {
            throw new \InvalidArgumentException('A message needs at least one attachment.');
        }

        return new self(messageAttachments: array_values($attachments));
    }

    #[\NoDiscard]
    public function fallbackText(string $text): self
    {
        self::validateText($text);
        if ($this->contentBlocks === [] && $this->messageAttachments === []) {
            throw new \LogicException(
                'fallbackText() belongs to a block or attachment message. Use Message::text() for a text message.',
            );
        }

        return clone($this, ['textContent' => $text]);
    }

    #[\NoDiscard]
    public function withBlocks(Block ...$blocks): self
    {
        self::validateBlocks($blocks);
        if ($this->markdownContent !== null) {
            throw new \LogicException(
                'A markdown_text message cannot also contain blocks. Start with Message::blocks() instead.',
            );
        }

        return clone($this, ['contentBlocks' => array_values($blocks)]);
    }

    #[\NoDiscard]
    public function addBlock(Block $block): self
    {
        return $this->withBlocks(...[...$this->contentBlocks, $block]);
    }

    #[\NoDiscard]
    public function withAttachments(Attachment ...$attachments): self
    {
        if ($attachments === []) {
            throw new \InvalidArgumentException('A message needs at least one attachment.');
        }
        if ($this->markdownContent !== null) {
            throw new \LogicException(
                'A markdown_text message cannot also contain attachments. Start with Message::attachments() instead.',
            );
        }

        return clone($this, ['messageAttachments' => array_values($attachments)]);
    }

    #[\NoDiscard]
    public function addAttachment(Attachment $attachment): self
    {
        return clone($this, ['messageAttachments' => [...$this->messageAttachments, $attachment]]);
    }

    #[\NoDiscard]
    public function inThread(string $rootTs): self
    {
        self::validateTimestamp($rootTs);

        return clone($this, ['threadTs' => $rootTs]);
    }

    #[\NoDiscard]
    public function replyBroadcast(bool $enabled = true): self
    {
        return clone($this, ['broadcastReply' => $enabled]);
    }

    #[\NoDiscard]
    public function metadata(MessageMetadata|string $metadata, array $payload = []): self
    {
        $metadata = is_string($metadata) ? MessageMetadata::event($metadata, $payload) : $metadata;

        return clone($this, ['messageMetadata' => $metadata]);
    }

    #[\NoDiscard]
    public function unfurlLinks(bool $enabled = true): self
    {
        return clone($this, ['unfurlTextLinks' => $enabled]);
    }

    #[\NoDiscard]
    public function unfurlMedia(bool $enabled = true): self
    {
        return clone($this, ['unfurlMediaContent' => $enabled]);
    }

    #[\NoDiscard]
    public function parse(Parse $parse): self
    {
        return clone($this, ['textParse' => $parse]);
    }

    #[\NoDiscard]
    public function linkNames(bool $enabled = true): self
    {
        return clone($this, ['linkChannelAndUserNames' => $enabled]);
    }

    #[\NoDiscard]
    public function mrkdwn(bool $enabled = true): self
    {
        return clone($this, ['useMarkdown' => $enabled]);
    }

    #[\NoDiscard]
    public function username(string $username): self
    {
        if ($username === '') {
            throw new \InvalidArgumentException('A bot username cannot be empty.');
        }

        return clone($this, ['botUsername' => $username, 'actAsUser' => false]);
    }

    #[\NoDiscard]
    public function iconEmoji(string $emoji): self
    {
        if ($emoji === '') {
            throw new \InvalidArgumentException('An icon emoji cannot be empty.');
        }

        return clone($this, ['botIconEmoji' => $emoji, 'botIconUrl' => null, 'actAsUser' => false]);
    }

    #[\NoDiscard]
    public function iconUrl(string $url): self
    {
        self::validateUrl($url, 'An icon URL');

        return clone($this, ['botIconUrl' => $url, 'botIconEmoji' => null, 'actAsUser' => false]);
    }

    #[\NoDiscard]
    public function asUser(): self
    {
        if ($this->botUsername !== null || $this->botIconEmoji !== null || $this->botIconUrl !== null) {
            throw new \LogicException('A message acting as a user cannot override its bot username or icon.');
        }

        return clone($this, ['actAsUser' => true]);
    }

    #[\NoDiscard]
    public function asBot(): self
    {
        return clone($this, ['actAsUser' => false]);
    }

    public function toArray(): array
    {
        $data = [];
        if ($this->textContent !== null)
            $data['text'] = $this->textContent;
        if ($this->markdownContent !== null)
            $data['markdown_text'] = $this->markdownContent;
        if ($this->contentBlocks !== [])
            $data['blocks'] = array_map(static fn(Block $block): array => $block->toArray(), $this->contentBlocks);
        if ($this->messageAttachments !== [])
            $data['attachments'] = array_map(
                static fn(Attachment $attachment): array => $attachment->toArray(),
                $this->messageAttachments,
            );
        if ($this->threadTs !== null)
            $data['thread_ts'] = $this->threadTs;
        if ($this->broadcastReply !== null)
            $data['reply_broadcast'] = $this->broadcastReply;
        if ($this->messageMetadata !== null)
            $data['metadata'] = $this->messageMetadata->toArray();
        if ($this->unfurlTextLinks !== null)
            $data['unfurl_links'] = $this->unfurlTextLinks;
        if ($this->unfurlMediaContent !== null)
            $data['unfurl_media'] = $this->unfurlMediaContent;
        if ($this->textParse !== null)
            $data['parse'] = $this->textParse->value;
        if ($this->linkChannelAndUserNames !== null)
            $data['link_names'] = $this->linkChannelAndUserNames;
        if ($this->useMarkdown !== null)
            $data['mrkdwn'] = $this->useMarkdown;
        if ($this->botUsername !== null)
            $data['username'] = $this->botUsername;
        if ($this->botIconEmoji !== null)
            $data['icon_emoji'] = $this->botIconEmoji;
        if ($this->botIconUrl !== null)
            $data['icon_url'] = $this->botIconUrl;
        if ($this->actAsUser !== null)
            $data['as_user'] = $this->actAsUser;

        return $data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function validateText(string $text): void
    {
        if ($text === '' || mb_strlen($text) > 40000) {
            throw new \InvalidArgumentException('Message text needs between 1 and 40000 characters.');
        }
    }

    /** @param array<array-key, Block> $blocks */
    private static function validateBlocks(array $blocks): void
    {
        if (count($blocks) < 1 || count($blocks) > 50) {
            throw new \InvalidArgumentException('A message needs between 1 and 50 blocks.');
        }
    }

    private static function validateTimestamp(string $timestamp): void
    {
        if (!preg_match('/\A[0-9]+\.[0-9]+\z/', $timestamp)) {
            throw new \InvalidArgumentException(
                'A Slack thread timestamp must be the parent message timestamp, such as 1234567890.123456.',
            );
        }
    }

    private static function validateUrl(string $url, string $description): void
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException("{$description} must be a valid URL.");
        }
    }
}
