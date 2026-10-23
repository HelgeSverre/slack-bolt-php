<?php

declare(strict_types=1);

/**
 * Structural, declaration-only DTO scaffold for Bolt JS `HTTPReceiverInstallerOptions`.
 * Source: https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/HTTPReceiver.ts#L104
 * Non-trivial TypeScript types remain `mixed`; this class is not a runtime contract.
 */
namespace Thunder\Bolt\Scaffold\Bolt\Receivers\HTTPReceiver;

use Tempest\Discovery\SkipDiscovery;

#[SkipDiscovery]
final readonly class HTTPReceiverInstallerOptions
{
    public function __construct(
        /** Bolt JS: string */
        public ?string $installPath = null,
        /** Bolt JS: InstallProviderOptions['directInstall'] */
        public mixed $directInstall = null,
        /** Bolt JS: InstallProviderOptions['renderHtmlForInstallPath'] */
        public mixed $renderHtmlForInstallPath = null,
        /** Bolt JS: string */
        public ?string $redirectUriPath = null,
        /** Bolt JS: InstallProviderOptions['stateStore'] */
        public mixed $stateStore = null,
        /** Bolt JS: InstallProviderOptions['stateVerification'] */
        public mixed $stateVerification = null,
        /** Bolt JS: InstallProviderOptions['legacyStateVerification'] */
        public mixed $legacyStateVerification = null,
        /** Bolt JS: InstallProviderOptions['stateCookieName'] */
        public mixed $stateCookieName = null,
        /** Bolt JS: InstallProviderOptions['stateCookieExpirationSeconds'] */
        public mixed $stateCookieExpirationSeconds = null,
        /** Bolt JS: InstallProviderOptions['authVersion'] */
        public mixed $authVersion = null,
        /** Bolt JS: InstallProviderOptions['clientOptions'] */
        public mixed $clientOptions = null,
        /** Bolt JS: InstallProviderOptions['authorizationUrl'] */
        public mixed $authorizationUrl = null,
        /** Bolt JS: InstallURLOptions['metadata'] */
        public mixed $metadata = null,
        /** Bolt JS: InstallURLOptions['userScopes'] */
        public mixed $userScopes = null,
        /** Bolt JS: InstallPathOptions */
        public mixed $installPathOptions = null,
        /** Bolt JS: CallbackOptions */
        public mixed $callbackOptions = null,
        /** Bolt JS: number */
        public int|float|null $port = null,
    ) {
    }
}
