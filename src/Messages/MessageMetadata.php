<?php

declare(strict_types=1);

namespace Thunder\Bolt\Messages;

final readonly class MessageMetadata implements \JsonSerializable
{
    private function __construct(
        public string $eventType,
        public array $eventPayload,
    ) {}

    public static function event(string $eventType, array $payload): self
    {
        if ($eventType === '' || !preg_match('/\A[a-zA-Z0-9._-]+\z/', $eventType)) {
            throw new \InvalidArgumentException('A metadata event type must be a non-empty alphanumeric identifier.');
        }

        self::validatePayload($payload);

        return new self($eventType, $payload);
    }

    public function toArray(): array
    {
        return ['event_type' => $this->eventType, 'event_payload' => $this->eventPayload];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function validatePayload(array $payload): void
    {
        array_walk($payload, static function (mixed $value, int|string $key): void {
            if (!is_string($key) || $key === '') {
                throw new \InvalidArgumentException('Metadata payload keys must be non-empty strings.');
            }

            self::validateValue($value);
        });
    }

    private static function validateValue(mixed $value): void
    {
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return;
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException(
                'Metadata payload values must be scalars, objects of scalars, or lists of scalar objects.',
            );
        }

        if (array_is_list($value)) {
            array_walk($value, static function (mixed $object): void {
                if (!is_array($object) || array_is_list($object)) {
                    throw new \InvalidArgumentException(
                        'Metadata payload lists must contain objects of scalar values.',
                    );
                }

                self::validateScalarObject($object);
            });

            return;
        }

        self::validateScalarObject($value);
    }

    private static function validateScalarObject(array $object): void
    {
        array_walk($object, static function (mixed $value, int|string $key): void {
            if (
                !is_string($key)
                || $key === ''
                || !(is_string($value) || is_int($value) || is_float($value) || is_bool($value))
            ) {
                throw new \InvalidArgumentException(
                    'Metadata payload objects may contain only non-empty string keys and scalar values.',
                );
            }
        });
    }
}
