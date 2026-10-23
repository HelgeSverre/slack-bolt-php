<?php

declare(strict_types=1);

namespace Thunder\Bolt\Messages;

use Thunder\Bolt\BlockKit\Block;

final readonly class Attachment implements \JsonSerializable
{
    /** @param list<Block> $contentBlocks @param list<AttachmentField> $attachmentFields @param list<AttachmentMarkdown> $markdownFields */
    private function __construct(
        private ?string $fallbackText = null,
        private ?string $bodyText = null,
        private array $contentBlocks = [],
        private ?string $borderColor = null,
        private ?string $pretextContent = null,
        private ?string $authorName = null,
        private ?string $authorLink = null,
        private ?string $authorIcon = null,
        private ?string $authorSubname = null,
        private ?string $titleText = null,
        private ?string $titleLink = null,
        private array $attachmentFields = [],
        private ?string $imageUrl = null,
        private ?string $thumbnailUrl = null,
        private ?string $footerText = null,
        private ?string $footerIconUrl = null,
        private ?string $timestamp = null,
        private array $markdownFields = [],
        private ?string $callbackId = null,
        private ?string $appUnfurlUrl = null,
        private ?bool $isAppUnfurl = null,
        private ?string $appId = null,
        private ?string $botId = null,
    ) {}

    public static function text(string $text): self
    {
        self::nonEmpty($text, 'Attachment text');

        return new self(bodyText: $text);
    }

    public static function fallback(string $text): self
    {
        self::nonEmpty($text, 'Attachment fallback text');

        return new self(fallbackText: $text);
    }

    public static function blocks(Block ...$blocks): self
    {
        self::validateBlocks($blocks);

        return new self(contentBlocks: array_values($blocks));
    }

    #[\NoDiscard]
    public function fallbackText(string $text): self
    {
        self::nonEmpty($text, 'Attachment fallback text');

        return clone($this, ['fallbackText' => $text]);
    }

    #[\NoDiscard]
    public function textContent(string $text): self
    {
        self::nonEmpty($text, 'Attachment text');

        return clone($this, ['bodyText' => $text]);
    }

    #[\NoDiscard]
    public function withBlocks(Block ...$blocks): self
    {
        self::validateBlocks($blocks);

        return clone($this, ['contentBlocks' => array_values($blocks)]);
    }

    #[\NoDiscard]
    public function addBlock(Block $block): self
    {
        return $this->withBlocks(...[...$this->contentBlocks, $block]);
    }

    #[\NoDiscard]
    public function color(string $color): self
    {
        self::nonEmpty($color, 'Attachment color');

        return clone($this, ['borderColor' => $color]);
    }

    #[\NoDiscard]
    public function pretext(string $text): self
    {
        self::nonEmpty($text, 'Attachment pretext');

        return clone($this, ['pretextContent' => $text]);
    }

    #[\NoDiscard]
    public function author(string $name, ?string $link = null, ?string $icon = null): self
    {
        self::nonEmpty($name, 'Attachment author name');
        if ($link !== null)
            self::url($link, 'Attachment author link');
        if ($icon !== null)
            self::url($icon, 'Attachment author icon');

        return clone($this, ['authorName' => $name, 'authorLink' => $link, 'authorIcon' => $icon]);
    }

    #[\NoDiscard]
    public function authorSubname(string $subname): self
    {
        self::nonEmpty($subname, 'Attachment author subname');

        return clone($this, ['authorSubname' => $subname]);
    }

    #[\NoDiscard]
    public function title(string $title, ?string $link = null): self
    {
        self::nonEmpty($title, 'Attachment title');
        if ($link !== null)
            self::url($link, 'Attachment title link');

        return clone($this, ['titleText' => $title, 'titleLink' => $link]);
    }

    #[\NoDiscard]
    public function fields(AttachmentField ...$fields): self
    {
        return clone($this, ['attachmentFields' => array_values($fields)]);
    }

    #[\NoDiscard]
    public function addField(AttachmentField $field): self
    {
        return clone($this, ['attachmentFields' => [...$this->attachmentFields, $field]]);
    }

    #[\NoDiscard]
    public function image(string $url): self
    {
        self::url($url, 'Attachment image URL');

        return clone($this, ['imageUrl' => $url, 'thumbnailUrl' => null]);
    }

    #[\NoDiscard]
    public function thumbnail(string $url): self
    {
        self::url($url, 'Attachment thumbnail URL');

        return clone($this, ['thumbnailUrl' => $url, 'imageUrl' => null]);
    }

    #[\NoDiscard]
    public function footer(string $text, ?string $icon = null): self
    {
        if ($text === '' || mb_strlen($text) > 300) {
            throw new \InvalidArgumentException('Attachment footer text needs between 1 and 300 characters.');
        }
        if ($icon !== null)
            self::url($icon, 'Attachment footer icon');

        return clone($this, ['footerText' => $text, 'footerIconUrl' => $icon]);
    }

    #[\NoDiscard]
    public function timestamp(int|\DateTimeInterface|string $timestamp): self
    {
        $timestamp = $timestamp instanceof \DateTimeInterface
            ? (string) $timestamp->getTimestamp()
            : (string) $timestamp;
        if (!ctype_digit($timestamp)) {
            throw new \InvalidArgumentException('An attachment timestamp must be a Unix timestamp.');
        }

        return clone($this, ['timestamp' => $timestamp]);
    }

    #[\NoDiscard]
    public function markdownIn(AttachmentMarkdown ...$fields): self
    {
        return clone($this, ['markdownFields' => array_values($fields)]);
    }

    #[\NoDiscard]
    public function callbackId(string $id): self
    {
        self::nonEmpty($id, 'Attachment callback ID');

        return clone($this, ['callbackId' => $id]);
    }

    #[\NoDiscard]
    public function appUnfurl(string $url, bool $enabled = true): self
    {
        self::url($url, 'Attachment app unfurl URL');

        return clone($this, ['appUnfurlUrl' => $url, 'isAppUnfurl' => $enabled]);
    }

    #[\NoDiscard]
    public function app(string $id): self
    {
        self::nonEmpty($id, 'Attachment app ID');

        return clone($this, ['appId' => $id]);
    }

    #[\NoDiscard]
    public function bot(string $id): self
    {
        self::nonEmpty($id, 'Attachment bot ID');

        return clone($this, ['botId' => $id]);
    }

    public function toArray(): array
    {
        if ($this->fallbackText === null && $this->bodyText === null && $this->contentBlocks === []) {
            throw new \LogicException('An attachment needs fallback text, text, or blocks.');
        }

        $data = [];
        if ($this->contentBlocks !== [])
            $data['blocks'] = array_map(static fn(Block $block): array => $block->toArray(), $this->contentBlocks);
        if ($this->fallbackText !== null)
            $data['fallback'] = $this->fallbackText;
        if ($this->borderColor !== null)
            $data['color'] = $this->borderColor;
        if ($this->pretextContent !== null)
            $data['pretext'] = $this->pretextContent;
        if ($this->authorName !== null)
            $data['author_name'] = $this->authorName;
        if ($this->authorLink !== null)
            $data['author_link'] = $this->authorLink;
        if ($this->authorIcon !== null)
            $data['author_icon'] = $this->authorIcon;
        if ($this->authorSubname !== null)
            $data['author_subname'] = $this->authorSubname;
        if ($this->titleText !== null)
            $data['title'] = $this->titleText;
        if ($this->titleLink !== null)
            $data['title_link'] = $this->titleLink;
        if ($this->bodyText !== null)
            $data['text'] = $this->bodyText;
        if ($this->attachmentFields !== [])
            $data['fields'] = array_map(
                static fn(AttachmentField $field): array => $field->toArray(),
                $this->attachmentFields,
            );
        if ($this->imageUrl !== null)
            $data['image_url'] = $this->imageUrl;
        if ($this->thumbnailUrl !== null)
            $data['thumb_url'] = $this->thumbnailUrl;
        if ($this->footerText !== null)
            $data['footer'] = $this->footerText;
        if ($this->footerIconUrl !== null)
            $data['footer_icon'] = $this->footerIconUrl;
        if ($this->timestamp !== null)
            $data['ts'] = $this->timestamp;
        if ($this->markdownFields !== [])
            $data['mrkdwn_in'] = array_map(
                static fn(AttachmentMarkdown $field): string => $field->value,
                $this->markdownFields,
            );
        if ($this->callbackId !== null)
            $data['callback_id'] = $this->callbackId;
        if ($this->appUnfurlUrl !== null)
            $data['app_unfurl_url'] = $this->appUnfurlUrl;
        if ($this->isAppUnfurl !== null)
            $data['is_app_unfurl'] = $this->isAppUnfurl;
        if ($this->appId !== null)
            $data['app_id'] = $this->appId;
        if ($this->botId !== null)
            $data['bot_id'] = $this->botId;

        return $data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function nonEmpty(string $value, string $description): void
    {
        if ($value === '')
            throw new \InvalidArgumentException("{$description} cannot be empty.");
    }

    private static function url(string $url, string $description): void
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException("{$description} must be a valid URL.");
        }
    }

    /** @param array<array-key, Block> $blocks */
    private static function validateBlocks(array $blocks): void
    {
        if ($blocks === [] || count($blocks) > 50) {
            throw new \InvalidArgumentException('An attachment needs between 1 and 50 blocks.');
        }
    }
}
