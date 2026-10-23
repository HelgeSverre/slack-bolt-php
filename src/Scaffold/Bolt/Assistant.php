<?php

declare(strict_types=1);

/**
 * Declaration-only quick scaffold for Bolt JS `Assistant`.
 * Source: https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/Assistant.ts#L99
 * Unsupported TypeScript signatures are deliberately represented as `mixed`.
 */
namespace Thunder\Bolt\Scaffold\Bolt;

use Thunder\Bolt\Scaffold\Bolt\NotPortedYet;
use Tempest\Discovery\SkipDiscovery;

#[SkipDiscovery]

class Assistant
{
    public function __construct(mixed ...$arguments)
    {
        NotPortedYet::forSymbol('Assistant::__construct');
    }

    /** Bolt JS `getMiddleware`; source type: `Middleware<AnyMiddlewareArgs>`. */
    public function getMiddleware(mixed ...$arguments): mixed
    {
        NotPortedYet::forSymbol('Assistant::getMiddleware');
    }
}
