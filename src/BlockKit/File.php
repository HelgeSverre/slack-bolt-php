<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class File implements Block
{
    private function __construct(
        private string $externalId,
        private string $source,
    ) {}

    public static function make(string $externalId, string $source = 'remote'): self
    {
        if ($externalId === '' || $source === '') {
            throw new \InvalidArgumentException('A file block needs an external ID and source.');
        }

        return new self($externalId, $source);
    }

    public static function remote(string $externalId): self
    {
        return self::make($externalId);
    }

    public function toArray(): array
    {
        return [
            'type' => 'file',
            'external_id' => $this->externalId,
            'source' => $this->source,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
