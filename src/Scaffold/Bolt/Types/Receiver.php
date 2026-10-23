<?php

declare(strict_types=1);

/**
 * Declaration-only quick scaffold for Bolt JS `Receiver`.
 * Source: https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/receiver.ts#L26
 * Unsupported TypeScript signatures are deliberately represented as `mixed`.
 */
namespace Thunder\Bolt\Scaffold\Bolt\Types;

use Tempest\Discovery\SkipDiscovery;

#[SkipDiscovery]
interface Receiver
{
    /** Bolt JS `init`; source type: `void`. */
    public function init(mixed ...$arguments): mixed;

    /** Bolt JS `start`; source type: `Promise<unknown>`. */
    public function start(mixed ...$arguments): mixed;

    /** Bolt JS `stop`; source type: `Promise<unknown>`. */
    public function stop(mixed ...$arguments): mixed;
}
