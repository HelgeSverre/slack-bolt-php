<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class SlackFile implements \JsonSerializable
{
    private function __construct(
        private string $key,
        private string $value,
    ) {}

    public static function id(string $id): self
    {
        if ($id === '') {
            throw new \InvalidArgumentException('A Slack file ID is required.');
        }
        return new self('id', $id);
    }

    public static function url(string $url): self
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('A Slack file URL must be absolute.');
        }
        return new self('url', $url);
    }

    public function toArray(): array
    {
        return [$this->key => $this->value];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
