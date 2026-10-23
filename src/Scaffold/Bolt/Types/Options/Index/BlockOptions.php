<?php

declare(strict_types=1);

/**
 * Structural, declaration-only DTO scaffold for Bolt JS `BlockOptions`.
 * Source: https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/types/options/index.ts#L160
 * Non-trivial TypeScript types remain `mixed`; this class is not a runtime contract.
 */
namespace Thunder\Bolt\Scaffold\Bolt\Types\Options\Index;

use Tempest\Discovery\SkipDiscovery;

#[SkipDiscovery]
final readonly class BlockOptions
{
    public function __construct(
        /** Bolt JS: Option[] */
        public mixed $options,
    ) {
    }
}
