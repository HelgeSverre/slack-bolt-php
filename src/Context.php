<?php

declare(strict_types=1);

namespace Thunder\Bolt;

/** Request-local values shared by middleware and handlers, never durable state. */
final class Context
{
    private array $values = [];

    public function set(string $key, mixed $value): self
    {
        if ($key === '') {
            throw new \InvalidArgumentException('A context key must not be empty.');
        }
        $this->values[$key] = $value;
        return $this;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function require(string $key): mixed
    {
        return $this->has($key)
            ? $this->values[$key]
            : throw new \OutOfBoundsException("Missing context value: {$key}.");
    }

    public function remove(string $key): void
    {
        unset($this->values[$key]);
    }

    public function __debugInfo(): array
    {
        return ['keys' => array_keys($this->values)];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Do not serialize request context. Dispatch explicit named job data.');
    }
}
