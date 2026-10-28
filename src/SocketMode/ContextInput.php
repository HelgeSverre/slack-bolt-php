<?php

declare(strict_types=1);

namespace Thunder\Bolt\SocketMode;

use Thunder\Bolt\Invocation\Delivery;

/** A Slack delivery envelope on the authenticated Socket Mode connection. */
final readonly class ContextInput
{
    public function __construct(
        public Delivery $delivery,
        public array $envelope,
    ) {}

    public function __debugInfo(): array
    {
        return ['transport' => 'socket_mode', 'envelopeKeys' => array_keys($this->envelope)];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Do not serialize delivery factory input. Dispatch explicit named job data.');
    }
}
