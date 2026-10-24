<?php

declare(strict_types=1);

namespace Thunder\Bolt\WorkObjects\Comments;

use Thunder\Bolt\WorkObjects\Actor;
use Thunder\Bolt\WorkObjects\ExternalReference;

final readonly class CommentQuery
{
    public function __construct(
        public Actor $actor,
        public ExternalReference $reference,
        public ?string $threadRootId = null,
        public ?string $cursor = null,
        public int $limit = 100,
    ) {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Comment page limits must be between 1 and 100.');
        }
    }
}
