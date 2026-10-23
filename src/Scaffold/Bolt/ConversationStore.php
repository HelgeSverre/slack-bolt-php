<?php

declare(strict_types=1);

/**
 * Declaration-only quick scaffold for Bolt JS `ConversationStore`.
 * Source: https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/conversation-store.ts#L8
 * Unsupported TypeScript signatures are deliberately represented as `mixed`.
 */
namespace Thunder\Bolt\Scaffold\Bolt;

use Tempest\Discovery\SkipDiscovery;

#[SkipDiscovery]
interface ConversationStore
{
    /** Bolt JS `set`; source type: `Promise<unknown>`. */
    public function set(mixed ...$arguments): mixed;

    /** Bolt JS `get`; source type: `Promise<ConversationState>`. */
    public function get(mixed ...$arguments): mixed;
}
