<?php

declare(strict_types=1);

namespace Thunder\Bolt\OAuth\State;

final class InMemoryStateStore implements StateStore
{
    /** @var array<string, array{cookieHash: string, expiresAt: int}> */
    private array $states = [];

    public function put(State $state): void
    {
        $key = $this->key($state->value);
        if (isset($this->states[$key])) {
            throw new \LogicException('An OAuth state collision occurred.');
        }

        $this->states[$key] = [
            'cookieHash' => hash('sha256', $state->cookieBinding),
            'expiresAt' => $state->expiresAt,
        ];
    }

    public function consume(string $value, string $cookieBinding, int $now): bool
    {
        $key = $this->key($value);
        $state = $this->states[$key] ?? null;
        unset($this->states[$key]);

        return $state !== null
        && $state['expiresAt'] > $now
        && hash_equals($state['cookieHash'], hash('sha256', $cookieBinding));
    }

    private function key(string $value): string
    {
        return hash('sha256', $value);
    }
}
