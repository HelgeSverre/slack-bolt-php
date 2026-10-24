<?php

declare(strict_types=1);

namespace Thunder\Bolt;

use Thunder\Bolt\Assistant\Handler;
use Thunder\Bolt\Assistant\ThreadContextStore;

final readonly class Assistant
{
    private function __construct(
        public ?Handler $started = null,
        public ?Handler $contextChanged = null,
        public ?Handler $message = null,
        public ?ThreadContextStore $store = null,
    ) {}

    public static function make(): self
    {
        return new self();
    }

    #[\NoDiscard]
    public function threadStarted(mixed ...$middlewareAndHandler): self
    {
        return clone($this, ['started' => Handler::fromArguments(array_values($middlewareAndHandler))]);
    }

    #[\NoDiscard]
    public function threadContextChanged(mixed ...$middlewareAndHandler): self
    {
        return clone($this, ['contextChanged' => Handler::fromArguments(array_values($middlewareAndHandler))]);
    }

    #[\NoDiscard]
    public function userMessage(mixed ...$middlewareAndHandler): self
    {
        return clone($this, ['message' => Handler::fromArguments(array_values($middlewareAndHandler))]);
    }

    #[\NoDiscard]
    public function contextStore(ThreadContextStore $store): self
    {
        return clone($this, ['store' => $store]);
    }

    public function validate(): void
    {
        if ($this->started === null || $this->message === null) {
            throw new \InvalidArgumentException('An assistant requires threadStarted and userMessage handlers.');
        }
    }
}
