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
        public bool $agentView = false,
        public ?Handler $opened = null,
        public ?Handler $appContextChanged = null,
    ) {}

    public static function make(): self
    {
        return new self();
    }

    public static function forAgentView(): self
    {
        return new self(agentView: true);
    }

    #[\NoDiscard]
    public function opened(mixed ...$middlewareAndHandler): self
    {
        return clone($this, ['opened' => Handler::fromArguments(array_values($middlewareAndHandler))]);
    }

    #[\NoDiscard]
    public function appContextChanged(mixed ...$middlewareAndHandler): self
    {
        return clone($this, ['appContextChanged' => Handler::fromArguments(array_values($middlewareAndHandler))]);
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
        if ($this->agentView) {
            if ($this->started !== null || $this->contextChanged !== null || $this->store !== null) {
                throw new \InvalidArgumentException(
                    'Agent View uses delivery app context, not legacy thread lifecycle or context stores.',
                );
            }
            if ($this->message === null) {
                throw new \InvalidArgumentException('An Agent View assistant requires a userMessage handler.');
            }
            return;
        }
        if ($this->opened !== null || $this->appContextChanged !== null) {
            throw new \InvalidArgumentException('opened and appContextChanged require Assistant::forAgentView().');
        }
        if ($this->started === null || $this->message === null) {
            throw new \InvalidArgumentException('An assistant requires threadStarted and userMessage handlers.');
        }
    }
}
