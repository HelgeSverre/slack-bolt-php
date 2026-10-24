<?php

declare(strict_types=1);

namespace Thunder\Bolt\WorkObjects;

final readonly class CommentPage
{
    /** @param list<Comment> $comments */
    public function __construct(
        public array $comments = [],
        public ?string $cursor = null,
    ) {
        if (
            !array_is_list($comments)
            || count($comments) > 100
            || array_any($comments, static fn($comment): bool => !$comment instanceof Comment)
        ) {
            throw new \InvalidArgumentException('A comment page accepts up to 100 comments.');
        }
    }
}
