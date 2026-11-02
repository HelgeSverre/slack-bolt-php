<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads\Values;

use Thunder\Bolt\Exceptions\InvalidPayload;

/** @internal Shared wire validation; unknown fields stay in the original payload. */
final class EventFields
{
    public static function text(array $body, string $field): string
    {
        return (
            self::optionalText($body, $field) ?? throw new InvalidPayload(
                "An event requires a nonempty {$field} string.",
            )
        );
    }

    public static function optionalText(array $body, string $field): ?string
    {
        $value = $body[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || $value === '') {
            throw new InvalidPayload("An event has an invalid {$field} string.");
        }
        return $value;
    }

    public static function object(array $body, string $field): array
    {
        $value = $body[$field] ?? null;
        if (!is_array($value) || $value !== [] && array_is_list($value)) {
            throw new InvalidPayload("An event requires a {$field} object.");
        }
        return $value;
    }

    public static function type(array $body, string $type): void
    {
        if (($body['type'] ?? null) !== $type) {
            throw new InvalidPayload("Expected a {$type} event.");
        }
    }
}
