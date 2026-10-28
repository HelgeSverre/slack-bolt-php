<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads\Values;

final readonly class UploadedFile
{
    private function __construct(
        public string $id,
        public ?string $name,
        public ?string $title,
        public ?string $mimeType,
        public ?int $size,
        public ?string $privateUrl,
        public array $raw,
    ) {}

    public static function fromArray(array $file): self
    {
        $id = $file['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new \InvalidArgumentException('An uploaded file requires its Slack file ID.');
        }
        return new self(
            $id,
            self::string($file['name'] ?? null),
            self::string($file['title'] ?? null),
            self::string($file['mimetype'] ?? null),
            is_int($file['size'] ?? null) ? $file['size'] : null,
            self::string($file['url_private'] ?? null),
            $file,
        );
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
