<?php

declare(strict_types=1);

/**
 * Structural, declaration-only DTO scaffold for Bolt JS `RedirectOptions`.
 * Source: https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/verify-redirect-opts.ts#L8
 * Non-trivial TypeScript types remain `mixed`; this class is not a runtime contract.
 */
namespace Thunder\Bolt\Scaffold\Bolt\Receivers\VerifyRedirectOpts;

use Tempest\Discovery\SkipDiscovery;

#[SkipDiscovery]
final readonly class RedirectOptions
{
    public function __construct(
        /** Bolt JS: HTTPReceiverOptions['redirectUri'] */
        public mixed $redirectUri = null,
        /** Bolt JS: HTTPReceiverInstallerOptions['redirectUriPath'] */
        public mixed $redirectUriPath = null,
    ) {
    }
}
