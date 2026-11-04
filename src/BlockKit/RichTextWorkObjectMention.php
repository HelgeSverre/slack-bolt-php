<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class RichTextWorkObjectMention extends RichTextLeaf
{
    private function __construct(
        private string $entityId,
        private string $appId,
        private string $text,
        private string $url,
        private ?string $iconUrl,
        private ?bool $fullSizePreviewEnabled,
        private ?string $productName,
        private RichTextStyle $textStyle,
    ) {}

    public static function for(string $entityId, string $appId, string $text, string $url): self
    {
        self::required($entityId, 'entity ID');
        self::required($appId, 'app ID');
        self::required($text, 'display text');
        self::urlValue($url, 'Work Object');

        return new self($entityId, $appId, $text, $url, null, null, null, RichTextStyle::none());
    }

    #[\NoDiscard]
    public function icon(string $url): self
    {
        self::urlValue($url, 'product icon');

        return clone($this, ['iconUrl' => $url]);
    }

    #[\NoDiscard]
    public function fullSizePreview(bool $enabled = true): self
    {
        return clone($this, ['fullSizePreviewEnabled' => $enabled]);
    }

    #[\NoDiscard]
    public function product(string $name): self
    {
        self::required($name, 'product name');

        return clone($this, ['productName' => $name]);
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
            'type' => 'work_object_mention',
            'entity_id' => $this->entityId,
            'app_id' => $this->appId,
            'text' => $this->text,
            'url' => $this->url,
        ];
        if ($this->iconUrl !== null) {
            $data['icon_url'] = $this->iconUrl;
        }
        if ($this->fullSizePreviewEnabled !== null) {
            $data['full_size_preview_enabled'] = $this->fullSizePreviewEnabled;
        }
        if ($this->productName !== null) {
            $data['product_name'] = $this->productName;
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
            throw new \InvalidArgumentException("A rich-text Work Object mention needs a {$field}.");
        }
    }

    private static function urlValue(string $url, string $kind): void
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException("A rich-text {$kind} URL needs to be absolute.");
        }
    }
}
