<?php

declare(strict_types=1);

namespace App\Demo;

final readonly class DemoJob
{
    public function __construct(
        public string $mode,
        public string $text,
        public string $team,
        public string $user,
        public string $channel,
        public ?string $thread = null,
        public string $operation = 'open',
        public ?string $message = null,
    ) {
        if (
            !in_array($mode, ['pet', 'terminal', 'ai'], true)
            || $team === ''
            || $user === ''
            || $channel === ''
            || strlen($text) > 8000
            || !in_array($operation, ['open', 'input', 'care', 'interrupt', 'close'], true)
        ) {
            throw new \InvalidArgumentException('Invalid demo job.');
        }
    }

    public static function fromArray(array $data): self
    {
        foreach (['mode', 'text', 'team', 'user', 'channel', 'operation'] as $key) {
            if (!is_string($data[$key] ?? null)) {
                throw new \InvalidArgumentException('Invalid demo job field: ' . $key);
            }
        }
        foreach (['thread', 'message'] as $key) {
            if (isset($data[$key]) && !is_string($data[$key])) {
                throw new \InvalidArgumentException('Invalid optional demo job field: ' . $key);
            }
        }
        return new self(
            self::string($data, 'mode'),
            self::string($data, 'text'),
            self::string($data, 'team'),
            self::string($data, 'user'),
            self::string($data, 'channel'),
            self::optionalString($data, 'thread'),
            self::string($data, 'operation'),
            self::optionalString($data, 'message'),
        );
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public function inThread(string $thread): self
    {
        return clone($this, ['thread' => $thread]);
    }

    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Invalid demo job field: ' . $key);
        }
        return $value;
    }

    private static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new \InvalidArgumentException('Invalid optional demo job field: ' . $key);
        }
        return $value;
    }
}
