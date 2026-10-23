<?php

declare(strict_types=1);

namespace Thunder\Bolt\Routing;

use Closure;
use Thunder\Bolt\Exceptions\InvalidListener;

final readonly class HandlerTarget
{
    /** @param Closure|string|array{0: class-string, 1: string} $target */
    private function __construct(
        public Closure|string|array $target,
    ) {}

    /** @param Closure|string|array<mixed> $target */
    public static function from(Closure|string|array $target): self
    {
        if (is_string($target) && class_exists($target)) {
            return self::classMethod($target);
        }

        if (is_array($target)) {
            if (
                count($target) !== 2
                || !isset($target[0], $target[1])
                || !is_string($target[0])
                || !class_exists($target[0])
                || !is_string($target[1])
            ) {
                throw InvalidListener::invalidHandler();
            }

            return self::classMethod($target[0], $target[1]);
        }

        if (!is_callable($target) && !function_exists($target)) {
            throw InvalidListener::invalidHandler();
        }

        return new self($target);
    }

    /** @param class-string $class */
    public static function classMethod(string $class, string $method = '__invoke'): self
    {
        if (!class_exists($class) || !method_exists($class, $method)) {
            throw InvalidListener::invalidHandler("{$class}::{$method}");
        }
        $reflection = new \ReflectionMethod($class, $method);
        if (!$reflection->isPublic() || $reflection->isAbstract() || !new \ReflectionClass($class)->isInstantiable()) {
            throw InvalidListener::invalidHandler("{$class}::{$method}");
        }

        return new self([$class, $method]);
    }

    public function key(): string
    {
        return match (true) {
            $this->target instanceof Closure => 'closure:' . spl_object_id($this->target),
            is_array($this->target) => $this->target[0] . '::' . $this->target[1],
            default => $this->target,
        };
    }

    /** @return Closure|string|array{0: class-string, 1: string} */
    public function callable(): Closure|string|array
    {
        return $this->target;
    }

    public function isClassMethod(): bool
    {
        return is_array($this->target);
    }
}
