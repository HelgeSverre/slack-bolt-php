<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class ImageElement implements Element
{
    private function __construct(
        private ?string $url,
        private string $alt,
        private ?SlackFile $slackFile = null,
    ) {}

    public static function url(string $url, string $alt): self
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false || $alt === '' || mb_strlen($alt) > 2000)
            throw new \InvalidArgumentException('An image needs an absolute URL and alt text up to 2000 characters.');
        return new self($url, $alt);
    }

    public static function file(SlackFile|string $file, string $alt): self
    {
        if ($alt === '' || mb_strlen($alt) > 2000) {
            throw new \InvalidArgumentException('Image alt text needs between 1 and 2000 characters.');
        }
        return new self(null, $alt, is_string($file) ? SlackFile::id($file) : $file);
    }

    public function toArray(): array
    {
        return (
            $this->slackFile === null
                ? ['type' => 'image', 'image_url' => $this->url, 'alt_text' => $this->alt]
                : ['type' => 'image', 'slack_file' => $this->slackFile->toArray(), 'alt_text' => $this->alt]
        );
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
