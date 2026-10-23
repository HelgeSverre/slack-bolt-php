<?php

declare(strict_types=1);

/**
 * Declaration-only quick scaffold for Bolt JS `SocketModeResponseAck`.
 * Source: https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/SocketModeResponseAck.ts#L13
 * Unsupported TypeScript signatures are deliberately represented as `mixed`.
 */
namespace Thunder\Bolt\Scaffold\Bolt\Receivers;

use Thunder\Bolt\Scaffold\Bolt\NotPortedYet;
use Tempest\Discovery\SkipDiscovery;

#[SkipDiscovery]

class SocketModeResponseAck
{
    public function __construct(mixed ...$arguments)
    {
        NotPortedYet::forSymbol('SocketModeResponseAck::__construct');
    }

    /** Bolt JS `bind`; source type: `AckFn<SocketModeResponseBody>`. */
    public function bind(mixed ...$arguments): mixed
    {
        NotPortedYet::forSymbol('SocketModeResponseAck::bind');
    }
}
