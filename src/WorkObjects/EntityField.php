<?php

declare(strict_types=1);

namespace Thunder\Bolt\WorkObjects;

final readonly class EntityField implements \JsonSerializable
{
    private function __construct(
        private array $data,
    ) {}

    public static function text(string $value): self
    {
        return new self(['value' => $value]);
    }

    public static function markdown(string $value): self
    {
        return new self(['value' => $value, 'format' => 'markdown']);
    }

    public static function number(int|float $value): self
    {
        return new self(['value' => $value]);
    }

    public static function user(Sender $sender): self
    {
        return new self(['type' => 'slack#/types/user', 'user' => $sender->toArray()]);
    }

    public static function timestamp(int $value): self
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('A timestamp must be non-negative.');
        }
        return new self(['value' => $value, 'type' => 'slack#/types/timestamp']);
    }

    public static function date(string $date): self
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Expected a YYYY-MM-DD date.');
        }
        return new self(['type' => 'slack#/types/date', 'value' => $date]);
    }

    public static function status(string $value, ?string $color = null): self
    {
        Guard::text($value);
        return new self(array_filter(['value' => $value, 'tag_color' => $color], static fn($v): bool => $v !== null));
    }

    public static function image(string $url, string $altText): self
    {
        Guard::url($url);
        Guard::text($altText);
        return new self(['type' => 'slack#/types/image', 'image_url' => $url, 'alt_text' => $altText]);
    }

    public static function raw(array $data): self
    {
        if ($data === [] || array_is_list($data)) {
            throw new \InvalidArgumentException('An entity field must be an object.');
        }
        json_encode($data, JSON_THROW_ON_ERROR);
        return new self($data);
    }

    public function link(string $url): self
    {
        Guard::url($url);
        return clone($this, ['data' => [...$this->data, 'link' => $url]]);
    }

    public function editable(bool $enabled = true): self
    {
        return clone($this, ['data' => [...$this->data, 'edit' => ['enabled' => $enabled]]]);
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
