<?php

declare(strict_types=1);

/**
 * Declaration-only quick scaffold for Bolt JS `ResponseAck`.
 * Source: https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/receiver.ts#L34
 * Unsupported TypeScript signatures are deliberately represented as `mixed`.
 */
namespace Thunder\Bolt\Scaffold\Bolt\Types\Receiver;

use Tempest\Discovery\SkipDiscovery;

#[SkipDiscovery]
interface ResponseAck
{
    /** Bolt JS `bind`; source type: `AckFn<any>`. */
    public function bind(mixed ...$arguments): mixed;
}
