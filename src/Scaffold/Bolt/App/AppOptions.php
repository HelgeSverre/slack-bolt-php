<?php

declare(strict_types=1);

/**
 * Structural, declaration-only DTO scaffold for Bolt JS `AppOptions`.
 * Source: https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts#L122
 * Non-trivial TypeScript types remain `mixed`; this class is not a runtime contract.
 */
namespace Thunder\Bolt\Scaffold\Bolt\App;

use Tempest\Discovery\SkipDiscovery;

#[SkipDiscovery]
final readonly class AppOptions
{
    public function __construct(
        /** Bolt JS: HTTPReceiverOptions['signingSecret'] */
        public mixed $signingSecret = null,
        /** Bolt JS: HTTPReceiverOptions['endpoints'] */
        public mixed $endpoints = null,
        /** Bolt JS: HTTPReceiverOptions['port'] */
        public mixed $port = null,
        /** Bolt JS: HTTPReceiverOptions['customRoutes'] */
        public mixed $customRoutes = null,
        /** Bolt JS: HTTPReceiverOptions['processBeforeResponse'] */
        public mixed $processBeforeResponse = null,
        /** Bolt JS: HTTPReceiverOptions['signatureVerification'] */
        public mixed $signatureVerification = null,
        /** Bolt JS: HTTPReceiverOptions['clientId'] */
        public mixed $clientId = null,
        /** Bolt JS: HTTPReceiverOptions['clientSecret'] */
        public mixed $clientSecret = null,
        /** Bolt JS: HTTPReceiverOptions['stateSecret'] */
        public mixed $stateSecret = null,
        /** Bolt JS: HTTPReceiverOptions['redirectUri'] */
        public mixed $redirectUri = null,
        /** Bolt JS: HTTPReceiverOptions['installationStore'] */
        public mixed $installationStore = null,
        /** Bolt JS: HTTPReceiverOptions['scopes'] */
        public mixed $scopes = null,
        /** Bolt JS: HTTPReceiverOptions['installerOptions'] */
        public mixed $installerOptions = null,
        /** Bolt JS: ConversationStore | false */
        public mixed $convoStore = null,
        /** Bolt JS: AuthorizeResult['botToken'] */
        public mixed $token = null,
        /** Bolt JS: string */
        public ?string $appToken = null,
        /** Bolt JS: AuthorizeResult['botId'] */
        public mixed $botId = null,
        /** Bolt JS: AuthorizeResult['botUserId'] */
        public mixed $botUserId = null,
        /** Bolt JS: Authorize<boolean> */
        public mixed $authorize = null,
        /** Bolt JS: Receiver */
        public mixed $receiver = null,
        /** Bolt JS: Logger */
        public mixed $logger = null,
        /** Bolt JS: LogLevel */
        public mixed $logLevel = null,
        /** Bolt JS: boolean */
        public ?bool $ignoreSelf = null,
        /** Bolt JS: WebClientOptions */
        public mixed $clientOptions = null,
        /** Bolt JS: boolean */
        public ?bool $socketMode = null,
        /** Bolt JS: boolean */
        public ?bool $developerMode = null,
        /** Bolt JS: boolean */
        public ?bool $tokenVerificationEnabled = null,
        /** Bolt JS: boolean */
        public ?bool $deferInitialization = null,
        /** Bolt JS: boolean */
        public ?bool $extendedErrorHandler = null,
        /** Bolt JS: boolean */
        public ?bool $attachFunctionToken = null,
    ) {
    }
}
