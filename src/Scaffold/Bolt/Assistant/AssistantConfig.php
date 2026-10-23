<?php

declare(strict_types=1);

/**
 * Structural, declaration-only DTO scaffold for Bolt JS `AssistantConfig`.
 * Source: https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/Assistant.ts#L21
 * Non-trivial TypeScript types remain `mixed`; this class is not a runtime contract.
 */
namespace Thunder\Bolt\Scaffold\Bolt\Assistant;

use Tempest\Discovery\SkipDiscovery;

#[SkipDiscovery]
final readonly class AssistantConfig
{
    public function __construct(
        /** Bolt JS: AssistantThreadStartedMiddleware | AssistantThreadStartedMiddleware[] */
        public mixed $threadStarted,
        /** Bolt JS: AssistantUserMessageMiddleware | AssistantUserMessageMiddleware[] */
        public mixed $userMessage,
        /** Bolt JS: AssistantThreadContextStore */
        public mixed $threadContextStore = null,
        /** Bolt JS: AssistantThreadContextChangedMiddleware | AssistantThreadContextChangedMiddleware[] */
        public mixed $threadContextChanged = null,
    ) {
    }
}
