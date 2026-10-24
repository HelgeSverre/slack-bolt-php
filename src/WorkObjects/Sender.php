<?php

declare(strict_types=1);

namespace Thunder\Bolt\WorkObjects;

final readonly class Sender implements \JsonSerializable
{
    private function __construct(
        private array $data,
    ) {}

    public static function slack(string $userId): self
    {
        Guard::text($userId, 255);
        return new self(['user_id' => $userId]);
    }

    public static function external(string $name, ?string $iconUrl = null): self
    {
        Guard::text($name);
        $data = ['text' => $name];
        if ($iconUrl !== null) {
            Guard::url($iconUrl);
            $data['icon'] = ['url' => $iconUrl, 'alt_text' => $name];
        }
        return new self($data);
    }

    public static function fromArray(array $data): self
    {
        if (is_string($data['user_id'] ?? null)) {
            return self::slack($data['user_id']);
        }
        if (!is_string($data['text'] ?? null)) {
            throw new \InvalidArgumentException('A sender needs a Slack user or display name.');
        }
        $sender = self::external($data['text']);
        if (is_array($data['icon'] ?? null) && is_string($data['icon']['url'] ?? null)) {
            $sender = self::external($data['text'], $data['icon']['url']);
        }
        return $sender;
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
