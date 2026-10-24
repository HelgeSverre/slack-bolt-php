<?php

declare(strict_types=1);

namespace Thunder\Bolt\Jobs;

use Tempest\CommandBus\Async;
use Tempest\CommandBus\CommandBusConfig;
use Tempest\CommandBus\CommandHandler;
use Thunder\Bolt\Functions\InputMapper;

/** @internal JSON constructor data and the host's registered handlers form the allowlist. */
final readonly class JobCodec
{
    public function __construct(
        private CommandBusConfig $commands,
    ) {}

    public function handler(string $type): CommandHandler
    {
        $handler = $this->commands->handlers[$type] ?? null;
        if (!$handler instanceof CommandHandler || !is_subclass_of($type, Job::class)) {
            throw new PermanentFailure('No registered named job handler.');
        }
        $class = new \ReflectionClass($type);
        $method = $handler->handler;
        if (
            !$class->isReadOnly()
            || $class->isAnonymous()
            || !$class->isInstantiable()
            || $class->getAttributes(Async::class) !== []
            || $method->getAttribute(Async::class) !== null
        ) {
            throw new PermanentFailure('Jobs must be readonly data classes without Tempest Async dispatch.');
        }
        return $handler;
    }

    public function encode(Job $job): array
    {
        $this->handler($job::class);
        $reflection = new \ReflectionClass($job);
        $parameters = $reflection->getConstructor()?->getParameters() ?? [];
        $data = [];
        foreach ($parameters as $parameter) {
            if (!$parameter->isPromoted()) {
                throw new \InvalidArgumentException('Job constructor parameters must be public promoted properties.');
            }
            $property = $reflection->getProperty($parameter->getName());
            if (!$property->isPublic()) {
                throw new \InvalidArgumentException('Job data must be public. Never capture a client or credential.');
            }
            $data[$property->getName()] = self::value($property->getValue($job));
        }
        foreach ($reflection->getProperties() as $property) {
            if (!$property->isStatic() && !array_key_exists($property->getName(), $data)) {
                throw new \InvalidArgumentException('All job state must be constructor data.');
            }
        }
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        if (strlen($json) > 65536) {
            throw new \LengthException('Job inputs exceed 65536 bytes.');
        }
        return $data;
    }

    public function decode(string $type, array $data): Job
    {
        $this->handler($type);
        if (!class_exists($type)) {
            throw new PermanentFailure('Stored job class is unavailable.');
        }
        try {
            $reflection = new \ReflectionClass($type);
            $job = $reflection->getConstructor() === null ? $reflection->newInstance() : InputMapper::map($data, $type);
        } catch (\Thunder\Bolt\Functions\InvalidInputs) {
            throw new PermanentFailure('Stored job inputs no longer match the registered data class.');
        }
        if (!$job instanceof Job) {
            throw new PermanentFailure('Invalid stored job type.');
        }
        if (json_encode($this->encode($job), JSON_THROW_ON_ERROR) !== json_encode($data, JSON_THROW_ON_ERROR)) {
            throw new PermanentFailure('Stored job inputs require an explicit schema migration.');
        }
        return $job;
    }

    private static function value(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if (is_array($value)) {
            return array_map(self::value(...), $value);
        }
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        throw new \InvalidArgumentException('Job inputs accept JSON values and backed enums, not live objects.');
    }
}
