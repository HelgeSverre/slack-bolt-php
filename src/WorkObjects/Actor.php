<?php

declare(strict_types=1);

namespace Thunder\Bolt\WorkObjects;

use Thunder\Bolt\Invocation\Delivery;

final readonly class Actor
{
    public function __construct(
        public string $teamId,
        public string $userId,
    ) {
        Guard::text($teamId, 255);
        Guard::text($userId, 255);
    }

    public static function fromDelivery(Delivery $delivery): self
    {
        return new self($delivery->authorizationSource?->teamId ?? '', $delivery->authorizationSource?->userId ?? '');
    }
}
