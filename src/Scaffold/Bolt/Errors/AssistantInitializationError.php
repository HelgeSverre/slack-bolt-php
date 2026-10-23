<?php

declare(strict_types=1);

/**
 * Declaration-only quick scaffold for Bolt JS `AssistantInitializationError`.
 * Source: https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/errors.ts#L76
 * Unsupported TypeScript signatures are deliberately represented as `mixed`.
 */
namespace Thunder\Bolt\Scaffold\Bolt\Errors;

use Thunder\Bolt\Scaffold\Bolt\NotPortedYet;
use Tempest\Discovery\SkipDiscovery;

#[SkipDiscovery]

class AssistantInitializationError
{
    public function __construct(mixed ...$arguments)
    {
        NotPortedYet::forSymbol('AssistantInitializationError::__construct');
    }
}
