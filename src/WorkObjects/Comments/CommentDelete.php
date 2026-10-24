<?php

declare(strict_types=1);

namespace Thunder\Bolt\WorkObjects\Comments;

use Thunder\Bolt\WorkObjects\Actor;
use Thunder\Bolt\WorkObjects\ExternalReference;
use Thunder\Bolt\WorkObjects\Guard;

final readonly class CommentDelete
{
    public function __construct(
        public Actor $actor,
        public ExternalReference $reference,
        public string $commentId,
        public string $deliveryId,
    ) {
        Guard::text($commentId, 255);
        Guard::text($deliveryId, 512);
    }
}
