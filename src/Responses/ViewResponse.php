<?php

declare(strict_types=1);

namespace Thunder\Bolt\Responses;

use Thunder\Bolt\Views\View;

final readonly class ViewResponse implements Acknowledgement
{
    /** @param array<string,mixed> $body */ private function __construct(
        private array $body,
    ) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /** @param array<array-key,mixed> $errors */ public static function errors(array $errors): self
    {
        if ($errors === [])
            throw new \InvalidArgumentException('A view error response requires at least one block error.');
        $normalized = [];
        foreach ($errors as $id => $error) {
            if (!is_string($error) || (string) $id === '' || $error === '')
                throw new \InvalidArgumentException('View error block IDs and messages must not be empty.');
            $normalized[(string) $id] = $error;
        }
        return new self(['response_action' => 'errors', 'errors' => (object) $normalized]);
    }

    public static function update(array|View $modal): self
    {
        return new self(['response_action' => 'update', 'view' => self::modal($modal)]);
    }

    public static function push(array|View $modal): self
    {
        return new self(['response_action' => 'push', 'view' => self::modal($modal)]);
    }

    public static function clear(): self
    {
        return new self(['response_action' => 'clear']);
    }

    /** @return array<string,mixed> */ public function toArray(): array
    {
        return $this->body;
    }

    private static function modal(array|View $modal): array
    {
        $modal = $modal instanceof View ? $modal->toArray() : $modal;
        if (($modal['type'] ?? null) !== 'modal')
            throw new \InvalidArgumentException('A view response requires a modal object with type "modal".');
        return $modal;
    }
}
