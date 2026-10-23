<?php

declare(strict_types=1);

/**
 * Structural, declaration-only DTO scaffold for Bolt JS `HTTPReceiverOptions`.
 * Source: https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/HTTPReceiver.ts#L76
 * Non-trivial TypeScript types remain `mixed`; this class is not a runtime contract.
 */
namespace Thunder\Bolt\Scaffold\Bolt\Receivers\HTTPReceiver;

use Tempest\Discovery\SkipDiscovery;

#[SkipDiscovery]
final readonly class HTTPReceiverOptions
{
    public function __construct(
        /** Bolt JS: string */
        public string $signingSecret,
        /** Bolt JS: string | string[] */
        public mixed $endpoints = null,
        /** Bolt JS: number */
        public int|float|null $port = null,
        /** Bolt JS: CustomRoute[] */
        public mixed $customRoutes = null,
        /** Bolt JS: Logger */
        public mixed $logger = null,
        /** Bolt JS: LogLevel */
        public mixed $logLevel = null,
        /** Bolt JS: boolean */
        public ?bool $processBeforeResponse = null,
        /** Bolt JS: boolean */
        public ?bool $signatureVerification = null,
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
        /** Bolt JS: HTTPReceiverInstallerOptions */
        public mixed $installerOptions = null,
        /** Bolt JS: (request: BufferedIncomingMessage) => StringIndexed */
        public mixed $customPropertiesExtractor = null,
        /** Bolt JS: (args: httpFunc.ReceiverDispatchErrorHandlerArgs) => void */
        public mixed $dispatchErrorHandler = null,
        /** Bolt JS: (args: httpFunc.ReceiverProcessEventErrorHandlerArgs) => Promise<boolean> */
        public mixed $processEventErrorHandler = null,
        /** Bolt JS: (args: httpFunc.ReceiverUnhandledRequestHandlerArgs) => void */
        public mixed $unhandledRequestHandler = null,
        /** Bolt JS: number */
        public int|float|null $unhandledRequestTimeoutMillis = null,
        /** Bolt JS: number | string */
        public mixed $bodyLimit = null,
    ) {
    }
}
