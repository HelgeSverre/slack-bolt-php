<?php

declare(strict_types=1);

/**
 * Declaration-only quick scaffold for Bolt JS `CustomFunction`.
 * Source: https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/CustomFunction.ts#L62
 * Unsupported TypeScript signatures are deliberately represented as `mixed`.
 */
namespace Thunder\Bolt\Scaffold\Bolt;

use Thunder\Bolt\Scaffold\Bolt\NotPortedYet;
use Tempest\Discovery\SkipDiscovery;

#[SkipDiscovery]

class CustomFunction
{
    public function __construct(mixed ...$arguments)
    {
        NotPortedYet::forSymbol('CustomFunction::__construct');
    }

    /** Bolt JS `getListeners`; source type: `Middleware<AnyMiddlewareArgs>[]`. */
    public function getListeners(mixed ...$arguments): mixed
    {
        NotPortedYet::forSymbol('CustomFunction::getListeners');
    }
}
