<?php

declare(strict_types=1);

/**
 * Structural, declaration-only DTO scaffold for Bolt JS `SlackRequestVerificationOptions`.
 * Source: https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/verify-request.ts#L11
 * Non-trivial TypeScript types remain `mixed`; this class is not a runtime contract.
 */
namespace Thunder\Bolt\Scaffold\Bolt\Receivers\VerifyRequest;

use Tempest\Discovery\SkipDiscovery;

#[SkipDiscovery]
final readonly class SlackRequestVerificationOptions
{
    public function __construct(
        /** Bolt JS: string */
        public string $signingSecret,
        /** Bolt JS: string */
        public string $body,
        /** Bolt JS: { 'x-slack-signature': string; 'x-slack-request-timestamp': number; } */
        public mixed $headers,
        /** Bolt JS: number */
        public int|float|null $nowMilliseconds = null,
        /** Bolt JS: Logger */
        public mixed $logger = null,
    ) {
    }
}
