<?php

declare(strict_types=1);

namespace Thunder\Bolt\Conversation\Stores;

use Thunder\Bolt\Conversation\Exceptions\ConversationStorageException;

/** @internal Shared portable-JSON boundary for every conversation adapter. */
final class JsonValue
{
    /**
     * Validate and detach a value at the portable JSON boundary.
     *
     * This is useful to delivery-scoped facades that need a snapshot without
     * performing a second store read after a write.
     */
    public static function copy(mixed $value): mixed
    {
        return self::decode(self::encode($value));
    }

    public static function encode(mixed $value): string
    {
        self::validate($value);
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Conversation values must be portable JSON.', previous: $exception);
        }
    }

    public static function decode(string $json): mixed
    {
        try {
            return json_decode($json, true, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $exception) {
            throw new ConversationStorageException(
                'The stored conversation value is invalid JSON.',
                previous: $exception,
            );
        }
    }

    private static function validate(mixed $value): void
    {
        // PHP arrays can contain references to themselves. A bounded traversal
        // rejects those safely before json_encode reaches them, and mirrors the
        // JSON encoder's supported nesting depth.
        $pending = [[$value, 0]];
        while ($pending !== []) {
            $entry = array_pop($pending);
            [$candidate, $depth] = $entry;
            if ($depth >= 512) {
                throw new \InvalidArgumentException('Conversation values may not exceed 512 nested JSON levels.');
            }
            if ($candidate === null || is_string($candidate) || is_bool($candidate) || is_int($candidate)) {
                continue;
            }
            if (is_float($candidate)) {
                if (!is_finite($candidate)) {
                    throw new \InvalidArgumentException('Conversation values cannot contain non-finite numbers.');
                }

                continue;
            }
            if (!is_array($candidate)) {
                throw new \InvalidArgumentException(
                    'Conversation values must contain only JSON scalars, lists, and maps.',
                );
            }
            foreach ($candidate as $item) {
                $pending[] = [$item, $depth + 1];
            }
        }
    }
}
