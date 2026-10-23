<?php

declare(strict_types=1);

/**
 * Structural, declaration-only DTO scaffold for Bolt JS `SocketModeReceiverOptions`.
 * Source: https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/SocketModeReceiver.ts#L31
 * Non-trivial TypeScript types remain `mixed`; this class is not a runtime contract.
 */
namespace Thunder\Bolt\Scaffold\Bolt\Receivers\SocketModeReceiver;

use Tempest\Discovery\SkipDiscovery;

#[SkipDiscovery]
final readonly class SocketModeReceiverOptions
{
    public function __construct(
        /** Bolt JS: string */
        public string $appToken,
        /** Bolt JS: Logger */
        public mixed $logger = null,
        /** Bolt JS: LogLevel */
        public mixed $logLevel = null,
        /** Bolt JS: string */
        public ?string $clientId = null,
        /** Bolt JS: string */
        public ?string $clientSecret = null,
        /** Bolt JS: InstallProviderOptions['stateSecret'] */
        public mixed $stateSecret = null,
        /** Bolt JS: string */
        public ?string $redirectUri = null,
        /** Bolt JS: InstallProviderOptions['installationStore'] */
        public mixed $installationStore = null,
        /** Bolt JS: InstallURLOptions['scopes'] */
        public mixed $scopes = null,
        /** Bolt JS: InstallerOptions */
        public mixed $installerOptions = null,
        /** Bolt JS: SocketModeOptions['dispatcher'] */
        public mixed $dispatcher = null,
        /** Bolt JS: CustomRoute[] */
        public mixed $customRoutes = null,
        /** Bolt JS: number */
        public int|float|null $clientPingTimeout = null,
        /** Bolt JS: number */
        public int|float|null $serverPingTimeout = null,
        /** Bolt JS: boolean */
        public ?bool $pingPongLoggingEnabled = null,
        /** Bolt JS: boolean */
        public ?bool $autoReconnectEnabled = null,
        /** Bolt JS: (args: any) => StringIndexed */
        public mixed $customPropertiesExtractor = null,
        /** Bolt JS: (args: SocketModeReceiverProcessEventErrorHandlerArgs) => Promise<boolean> */
        public mixed $processEventErrorHandler = null,
    ) {
    }
}
