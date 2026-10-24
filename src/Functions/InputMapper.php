<?php

declare(strict_types=1);

namespace Thunder\Bolt\Functions;

use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionParameter;
use Thunder\Bolt\Attributes\InputName;

/** @internal */
final readonly class InputMapper
{
    /** @param array<array-key, mixed> $data */
    public static function map(array $data, string $class, string $prefix = ''): object
    {
        self::assertObject($data, $prefix);

        if (!class_exists($class)) {
            throw InvalidInputs::unsupported(self::rootPath($prefix), 'the DTO class does not exist');
        }

        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if (!$reflection->isInstantiable() || $constructor === null || !$constructor->isPublic()) {
            throw InvalidInputs::unsupported(self::rootPath($prefix), 'the DTO needs a public constructor');
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $arguments[] = self::valueFor($data, $parameter, $prefix, $reflection);
        }

        return (
            $reflection->newInstanceArgs($arguments) ?? throw InvalidInputs::unsupported(
                self::rootPath($prefix),
                'the DTO could not be instantiated',
            )
        );
    }

    /** @param array<array-key, mixed> $data */
    private static function valueFor(
        array $data,
        ReflectionParameter $parameter,
        string $prefix,
        ReflectionClass $dto,
    ): mixed {
        $path = self::path($prefix, self::inputName($parameter));
        self::assertPublicPromotedProperty($parameter, $dto, $path);

        $type = $parameter->getType();
        if (!$type instanceof ReflectionNamedType) {
            throw InvalidInputs::unsupported($path, 'only one native parameter type is supported');
        }

        $name = self::inputName($parameter);
        if (!array_key_exists($name, $data)) {
            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }

            if ($type->allowsNull()) {
                return null;
            }

            throw InvalidInputs::missing($path);
        }

        $value = $data[$name];
        if ($value === null) {
            if ($type->allowsNull()) {
                return null;
            }

            throw InvalidInputs::null($path);
        }

        return self::cast($value, $type, $path);
    }

    private static function cast(mixed $value, ReflectionNamedType $type, string $path): mixed
    {
        $name = $type->getName();

        if (!$type->isBuiltin()) {
            if (class_exists($name) && is_subclass_of($name, \BackedEnum::class)) {
                return self::backedEnum($value, new ReflectionEnum($name), $path);
            }

            if (!is_array($value)) {
                throw InvalidInputs::type($path, 'an object');
            }

            return self::map($value, $name, $path);
        }

        return match ($name) {
            'string' => is_string($value) ? $value : throw InvalidInputs::type($path, 'a string'),
            'int' => is_int($value) ? $value : throw InvalidInputs::type($path, 'an integer'),
            'float' => is_float($value) || is_int($value)
                ? (float) $value
                : throw InvalidInputs::type($path, 'a number'),
            'bool' => is_bool($value) ? $value : throw InvalidInputs::type($path, 'a boolean'),
            'array' => is_array($value) ? $value : throw InvalidInputs::type($path, 'an array'),
            default => throw InvalidInputs::unsupported($path, "the {$name} type is not supported"),
        };
    }

    private static function backedEnum(mixed $value, ReflectionEnum $enum, string $path): \BackedEnum
    {
        $backingType = $enum->getBackingType()?->getName();
        $matchesBackingType =
            $backingType === 'string' && is_string($value) || $backingType === 'int' && is_int($value);

        if (!$matchesBackingType) {
            throw InvalidInputs::type($path, $backingType === 'int' ? 'an integer' : 'a string');
        }

        foreach ($enum->getCases() as $case) {
            if ($case instanceof \ReflectionEnumBackedCase && $case->getBackingValue() === $value) {
                $enumValue = $case->getValue();
                if ($enumValue instanceof \BackedEnum) {
                    return $enumValue;
                }
            }
        }

        throw InvalidInputs::unsupported($path, 'the value is not a valid enum case');
    }

    private static function assertPublicPromotedProperty(
        ReflectionParameter $parameter,
        ReflectionClass $dto,
        string $path,
    ): void {
        if (!$parameter->isPromoted() || !$dto->hasProperty($parameter->getName())) {
            throw InvalidInputs::unsupported($path, 'DTO constructor parameters must be promoted public properties');
        }

        if (!$dto->getProperty($parameter->getName())->isPublic()) {
            throw InvalidInputs::unsupported($path, 'DTO constructor properties must be public');
        }
    }

    private static function inputName(ReflectionParameter $parameter): string
    {
        $attribute = $parameter->getAttributes(InputName::class)[0] ?? null;

        return $attribute === null ? $parameter->getName() : $attribute->newInstance()->name;
    }

    /** @param array<mixed> $data */
    private static function assertObject(array $data, string $prefix): void
    {
        foreach (array_keys($data) as $key) {
            if (!is_string($key)) {
                throw InvalidInputs::unsupported(self::rootPath($prefix), 'object keys must be strings');
            }
        }
    }

    private static function path(string $prefix, string $name): string
    {
        return $prefix === '' ? $name : "{$prefix}.{$name}";
    }

    private static function rootPath(string $prefix): string
    {
        return $prefix === '' ? 'root' : $prefix;
    }
}
