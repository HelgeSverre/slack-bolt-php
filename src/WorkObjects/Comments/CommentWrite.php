<?php

declare(strict_types=1);

namespace Thunder\Bolt\WorkObjects\Comments;

use Thunder\Bolt\WorkObjects\Actor;
use Thunder\Bolt\WorkObjects\CommentBody;
use Thunder\Bolt\WorkObjects\ExternalReference;
use Thunder\Bolt\WorkObjects\Guard;

final readonly class CommentWrite
{
    public function __construct(
        public Actor $actor,
        public ExternalReference $reference,
        public CommentBody $body,
        public string $deliveryId,
        public ?string $commentId = null,
        public ?string $threadRootId = null,
    ) {
        Guard::text($deliveryId, 512);
    }
}
