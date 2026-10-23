<?php

declare(strict_types=1);

/**
 * Structural, declaration-only DTO scaffold for Bolt JS `AwsLambdaReceiverOptions`.
 * Source: https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/AwsLambdaReceiver.ts#L84
 * Non-trivial TypeScript types remain `mixed`; this class is not a runtime contract.
 */
namespace Thunder\Bolt\Scaffold\Bolt\Receivers\AwsLambdaReceiver;

use Tempest\Discovery\SkipDiscovery;

#[SkipDiscovery]
final readonly class AwsLambdaReceiverOptions
{
    public function __construct(
        /** Bolt JS: string */
        public string $signingSecret,
        /** Bolt JS: Logger */
        public mixed $logger = null,
        /** Bolt JS: LogLevel */
        public mixed $logLevel = null,
        /** Bolt JS: boolean */
        public ?bool $signatureVerification = null,
        /** Bolt JS: (request: AwsEvent) => StringIndexed */
        public mixed $customPropertiesExtractor = null,
        /** Bolt JS: (args: ReceiverInvalidRequestSignatureHandlerArgs) => void */
        public mixed $invalidRequestSignatureHandler = null,
        /** Bolt JS: number */
        public int|float|null $unhandledRequestTimeoutMillis = null,
    ) {
    }
}
