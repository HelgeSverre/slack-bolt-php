<?php

declare(strict_types=1);

/**
 * Declaration-only quick scaffold for Bolt JS `DefaultThreadContextStore`.
 * Source: https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/AssistantThreadContextStore.ts#L20
 * Unsupported TypeScript signatures are deliberately represented as `mixed`.
 */
namespace Thunder\Bolt\Scaffold\Bolt\AssistantThreadContextStore;

use Thunder\Bolt\Scaffold\Bolt\NotPortedYet;
use Tempest\Discovery\SkipDiscovery;

#[SkipDiscovery]

class DefaultThreadContextStore
{
    public function __construct(mixed ...$arguments)
    {
        NotPortedYet::forSymbol('DefaultThreadContextStore::__construct');
    }

    /** Bolt JS `get`; source type: `Promise<AssistantThreadContext>`. */
    public function get(mixed ...$arguments): mixed
    {
        NotPortedYet::forSymbol('DefaultThreadContextStore::get');
    }

    /** Bolt JS `save`; source type: `Promise<void>`. */
    public function save(mixed ...$arguments): mixed
    {
        NotPortedYet::forSymbol('DefaultThreadContextStore::save');
    }
}
