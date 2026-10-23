<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class Video implements Block
{
    private function __construct(
        private Text $title,
        private string $videoUrl,
        private string $thumbnailUrl,
        private string $altText,
        private ?string $titleUrl = null,
        private ?string $authorName = null,
        private ?string $providerName = null,
        private ?string $providerIconUrl = null,
        private ?Text $description = null,
    ) {}

    public static function make(string|Text $title, string $videoUrl, string $thumbnailUrl, string $altText): self
    {
        $title = is_string($title) ? Text::plain($title) : $title;
        self::plainText($title, 200, 'A video title needs plain text up to 200 characters.');
        self::httpsUrl($videoUrl, 'A video URL must be an absolute HTTPS URL.');
        self::absoluteUrl($thumbnailUrl, 'A video thumbnail URL must be absolute.');
        if ($altText === '') {
            throw new \InvalidArgumentException('A video needs alt text.');
        }

        return new self($title, $videoUrl, $thumbnailUrl, $altText);
    }

    #[\NoDiscard]
    public function titleUrl(string $url): self
    {
        self::httpsUrl($url, 'A video title URL must be an absolute HTTPS URL.');

        return clone($this, ['titleUrl' => $url]);
    }

    #[\NoDiscard]
    public function author(string $name): self
    {
        if ($name === '' || mb_strlen($name) > 50) {
            throw new \InvalidArgumentException('A video author needs between 1 and 50 characters.');
        }

        return clone($this, ['authorName' => $name]);
    }

    #[\NoDiscard]
    public function provider(string $name, ?string $iconUrl = null): self
    {
        if ($name === '') {
            throw new \InvalidArgumentException('A video provider needs a name.');
        }
        if ($iconUrl !== null) {
            self::absoluteUrl($iconUrl, 'A video provider icon URL must be absolute.');
        }

        return clone($this, ['providerName' => $name, 'providerIconUrl' => $iconUrl]);
    }

    #[\NoDiscard]
    public function description(string|Text $description): self
    {
        $description = is_string($description) ? Text::plain($description) : $description;
        self::plainText($description, 3_000, 'A video description needs plain text up to 3000 characters.');

        return clone($this, ['description' => $description]);
    }

    public function toArray(): array
    {
        $data = [
            'type' => 'video',
            'video_url' => $this->videoUrl,
            'thumbnail_url' => $this->thumbnailUrl,
            'alt_text' => $this->altText,
            'title' => $this->title->toArray(),
        ];
        if ($this->titleUrl !== null) {
            $data['title_url'] = $this->titleUrl;
        }
        if ($this->authorName !== null) {
            $data['author_name'] = $this->authorName;
        }
        if ($this->providerName !== null) {
            $data['provider_name'] = $this->providerName;
        }
        if ($this->providerIconUrl !== null) {
            $data['provider_icon_url'] = $this->providerIconUrl;
        }
        if ($this->description !== null) {
            $data['description'] = $this->description->toArray();
        }

        return $data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function httpsUrl(string $url, string $message): void
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false || !str_starts_with($url, 'https://')) {
            throw new \InvalidArgumentException($message);
        }
    }

    private static function absoluteUrl(string $url, string $message): void
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException($message);
        }
    }

    private static function plainText(Text $text, int $maximum, string $message): void
    {
        if ($text->type !== 'plain_text' || mb_strlen($text->value) > $maximum) {
            throw new \InvalidArgumentException($message);
        }
    }
}
