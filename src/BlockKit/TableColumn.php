<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class TableColumn implements \JsonSerializable
{
    private function __construct(
        private ?string $alignment = null,
        private ?bool $wrapped = null,
    ) {}

    public static function make(?string $alignment = null, ?bool $wrapped = null): self
    {
        if ($alignment !== null && !in_array($alignment, ['left', 'center', 'right'], true)) {
            throw new \InvalidArgumentException('A table column alignment must be left, center, or right.');
        }

        return new self($alignment, $wrapped);
    }

    #[\NoDiscard]
    public function align(string $alignment): self
    {
        return self::make($alignment, $this->wrapped);
    }

    #[\NoDiscard]
    public function wrap(): self
    {
        return new self($this->alignment, true);
    }

    public function toArray(): array
    {
        $data = [];
        if ($this->alignment !== null) {
            $data['align'] = $this->alignment;
        }
        if ($this->wrapped !== null) {
            $data['is_wrapped'] = $this->wrapped;
        }

        return $data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
