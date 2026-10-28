<?php

declare(strict_types=1);

namespace Thunder\Bolt\Middleware;

use Closure;
use Thunder\Bolt\Invocation\Delivery;
use Thunder\Bolt\Payloads\Events\Message;

final readonly class MessageFilter implements Middleware
{
    private function __construct(
        private Closure $matches,
    ) {}

    public static function subtype(string $subtype): self
    {
        return new self(
            static fn(Delivery $delivery): bool => (
                $delivery->payload instanceof Message
                && ($delivery->payload->raw['subtype'] ?? null) === $subtype
            ),
        );
    }

    public static function directMention(): self
    {
        return new self(static function (Delivery $delivery): bool {
            $botUserId = $delivery->authorization?->botUserId;
            if ($botUserId === null || $botUserId === '') {
                throw new \LogicException(
                    'Direct mentions require a bot user ID. Set SLACK_BOT_USER_ID or verify the static token.',
                );
            }
            if (!$delivery->payload instanceof Message) {
                return false;
            }
            // Match Bolt's leading mention after ECMAScript whitespace (including BOM).
            return (
                preg_match(
                    '/\A[\p{Z}\x{0009}-\x{000D}\x{FEFF}]*<(?<type>[@#!])?(?<id>[^>|]+)(?:\|[^>]+)?>/u',
                    $delivery->payload->text,
                    $match,
                ) === 1
                && ($match['type'] ?? '') === '@'
                && $match['id'] === $botUserId
            );
        });
    }

    public function handle(Delivery $delivery, Closure $next): mixed
    {
        return ($this->matches)($delivery) ? $next() : null;
    }
}
