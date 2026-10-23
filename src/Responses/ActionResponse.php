<?php

declare(strict_types=1);

namespace Thunder\Bolt\Responses;

use Thunder\Bolt\Messages\Message;

final readonly class ActionResponse implements Acknowledgement
{
    /** @param array<string, mixed> $body */
    private function __construct(
        private array $body,
    ) {}

    public static function empty(): self
    {
        return self::fromArray([]);
    }

    public static function message(string|Message $message): self
    {
        return self::fromArray((is_string($message) ? Message::text($message) : $message)->toArray());
    }

    /**
     * Escape hatch for a legacy interactive-message acknowledgement with
     * attachments, replacement flags, or other Slack message fields.
     *
     * @param  array<array-key, mixed>  $body
     */
    public static function fromArray(array $body): self
    {
        $body = self::objectBody($body);
        if (array_key_exists('errors', $body)) {
            throw new \InvalidArgumentException('Use ActionResponse::errors() for legacy dialog errors.');
        }
        foreach (['text', 'response_type'] as $field) {
            if (array_key_exists($field, $body) && !is_string($body[$field])) {
                throw new \InvalidArgumentException("The {$field} field must be a string.");
            }
        }
        foreach (['replace_original', 'delete_original'] as $field) {
            if (array_key_exists($field, $body) && !is_bool($body[$field])) {
                throw new \InvalidArgumentException("The {$field} field must be a boolean.");
            }
        }
        foreach (['attachments', 'blocks'] as $field) {
            if (array_key_exists($field, $body) && (!is_array($body[$field]) || !array_is_list($body[$field]))) {
                throw new \InvalidArgumentException("The {$field} field must be a list.");
            }
        }

        return new self($body);
    }

    public static function errors(array $errors): self
    {
        if ($errors === []) {
            throw new \InvalidArgumentException('A legacy action error response requires at least one field error.');
        }

        $items = [];
        foreach ($errors as $name => $error) {
            if (!is_string($error) || (string) $name === '' || $error === '') {
                throw new \InvalidArgumentException('Legacy action error names and messages must not be empty.');
            }

            $items[] = ['name' => (string) $name, 'error' => $error];
        }

        return new self(['errors' => $items]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->body;
    }

    /**
     * @param  array<array-key, mixed>  $body
     * @return array<string, mixed>
     */
    private static function objectBody(array $body): array
    {
        foreach ($body as $key => $_) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Slack response fields must use string keys.');
            }
        }

        /** @var array<string, mixed> $body */
        return $body;
    }
}
