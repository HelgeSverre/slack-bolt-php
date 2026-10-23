<?php

declare(strict_types=1);

/**
 * Declaration-only quick scaffold for Bolt JS `MemoryStore`.
 * Source: https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/conversation-store.ts#L21
 * Unsupported TypeScript signatures are deliberately represented as `mixed`.
 */
namespace Thunder\Bolt\Scaffold\Bolt\ConversationStore;

use Thunder\Bolt\Scaffold\Bolt\NotPortedYet;
use Tempest\Discovery\SkipDiscovery;

#[SkipDiscovery]

class MemoryStore
{
    public function __construct(mixed ...$arguments)
    {
        NotPortedYet::forSymbol('MemoryStore::__construct');
    }

    /** Bolt JS `set`; source type: `Promise<void>`. */
    public function set(mixed ...$arguments): mixed
    {
        NotPortedYet::forSymbol('MemoryStore::set');
    }

    /** Bolt JS `get`; source type: `Promise<ConversationState>`. */
    public function get(mixed ...$arguments): mixed
    {
        NotPortedYet::forSymbol('MemoryStore::get');
    }
}
