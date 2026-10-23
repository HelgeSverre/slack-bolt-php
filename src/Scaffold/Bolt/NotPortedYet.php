<?php

declare(strict_types=1);

namespace Thunder\Bolt\Scaffold\Bolt;

use Tempest\Discovery\SkipDiscovery;

/** @internal Declaration-only scaffold; never a runtime Bolt implementation. */
#[SkipDiscovery]
final class NotPortedYet extends \LogicException
{
    public static function forSymbol(string $symbol): never
    {
        throw new self(sprintf('Bolt JS symbol "%s" is declared for migration planning but is not implemented.', $symbol));
    }
}
