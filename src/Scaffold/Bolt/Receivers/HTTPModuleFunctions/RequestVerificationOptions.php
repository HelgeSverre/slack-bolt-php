<?php

declare(strict_types=1);

/**
 * Structural, declaration-only DTO scaffold for Bolt JS `RequestVerificationOptions`.
 * Source: https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/HTTPModuleFunctions.ts#L242
 * Non-trivial TypeScript types remain `mixed`; this class is not a runtime contract.
 */
namespace Thunder\Bolt\Scaffold\Bolt\Receivers\HTTPModuleFunctions;

use Tempest\Discovery\SkipDiscovery;

#[SkipDiscovery]
final readonly class RequestVerificationOptions
{
    public function __construct(
        /** Bolt JS: string */
        public string $signingSecret,
        /** Bolt JS: boolean */
        public ?bool $enabled = null,
        /** Bolt JS: () => number */
        public mixed $nowMilliseconds = null,
        /** Bolt JS: Logger */
        public mixed $logger = null,
        /** Bolt JS: number | string */
        public mixed $bodyLimit = null,
    ) {
    }
}
