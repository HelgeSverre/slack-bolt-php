<?php

declare(strict_types=1);

/**
 * Declaration-only quick scaffold for Bolt JS `HTTPResponseAck`.
 * Source: https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/HTTPResponseAck.ts#L21
 * Unsupported TypeScript signatures are deliberately represented as `mixed`.
 */
namespace Thunder\Bolt\Scaffold\Bolt\Receivers;

use Thunder\Bolt\Scaffold\Bolt\NotPortedYet;
use Tempest\Discovery\SkipDiscovery;

#[SkipDiscovery]

class HTTPResponseAck
{
    public function __construct(mixed ...$arguments)
    {
        NotPortedYet::forSymbol('HTTPResponseAck::__construct');
    }

    /** Bolt JS `bind`; source type: `AckFn<HTTResponseBody>`. */
    public function bind(mixed ...$arguments): mixed
    {
        NotPortedYet::forSymbol('HTTPResponseAck::bind');
    }

    /** Bolt JS `ack`; source type: `void`. */
    public function ack(mixed ...$arguments): mixed
    {
        NotPortedYet::forSymbol('HTTPResponseAck::ack');
    }
}
