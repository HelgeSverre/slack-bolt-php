<?php

declare(strict_types=1);

namespace Thunder\Bolt\Functions;

final readonly class Inputs implements \JsonSerializable
{
    /** @param array<array-key, mixed> $data */
    public function __construct(
        private array $data,
    ) {
        foreach (array_keys($data) as $key) {
            if (!is_string($key)) {
                throw new InvalidInputs('Function input object keys must be strings.');
            }
        }
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->data);
    }

    public function get(string $name, mixed $default = null): mixed
    {
        return $this->has($name) ? $this->data[$name] : $default;
    }

    public function string(string $name): string
    {
        $value = $this->required($name);

        if (!is_string($value)) {
            throw InvalidInputs::type($name, 'a string');
        }

        return $value;
    }

    public function integer(string $name): int
    {
        $value = $this->required($name);

        if (!is_int($value)) {
            throw InvalidInputs::type($name, 'an integer');
        }

        return $value;
    }

    public function number(string $name): int|float
    {
        $value = $this->required($name);

        if (!is_int($value) && !is_float($value)) {
            throw InvalidInputs::type($name, 'a number');
        }

        return $value;
    }

    public function boolean(string $name): bool
    {
        $value = $this->required($name);

        if (!is_bool($value)) {
            throw InvalidInputs::type($name, 'a boolean');
        }

        return $value;
    }

    /** @return array<mixed> */
    public function array(string $name): array
    {
        $value = $this->required($name);

        if (!is_array($value)) {
            throw InvalidInputs::type($name, 'an array');
        }

        return $value;
    }

    /** @return array<array-key, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    /** @return array<array-key, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function as(string $class): object
    {
        $mapped = InputMapper::map($this->data, $class);

        /** @var T $mapped */
        return $mapped;
    }

    private function required(string $name): mixed
    {
        if (!$this->has($name)) {
            throw InvalidInputs::missing($name);
        }

        return $this->data[$name];
    }
}
