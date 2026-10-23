<?php

declare(strict_types=1);

/**
 * Declaration-only quick scaffold for Bolt JS `SocketModeReceiver`.
 * Source: https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/SocketModeReceiver.ts#L83
 * Unsupported TypeScript signatures are deliberately represented as `mixed`.
 */
namespace Thunder\Bolt\Scaffold\Bolt\Receivers;

use Thunder\Bolt\Scaffold\Bolt\NotPortedYet;
use Tempest\Discovery\SkipDiscovery;

#[SkipDiscovery]

class SocketModeReceiver
{
    public function __construct(mixed ...$arguments)
    {
        NotPortedYet::forSymbol('SocketModeReceiver::__construct');
    }

    /** Bolt JS `init`; source type: `void`. */
    public function init(mixed ...$arguments): mixed
    {
        NotPortedYet::forSymbol('SocketModeReceiver::init');
    }

    /** Bolt JS `start`; source type: `Promise<AppsConnectionsOpenResponse>`. */
    public function start(mixed ...$arguments): mixed
    {
        NotPortedYet::forSymbol('SocketModeReceiver::start');
    }

    /** Bolt JS `stop`; source type: `Promise<void>`. */
    public function stop(mixed ...$arguments): mixed
    {
        NotPortedYet::forSymbol('SocketModeReceiver::stop');
    }
}
