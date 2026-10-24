<?php

declare(strict_types=1);

namespace Thunder\Bolt\WorkObjects\Comments;

use Thunder\Bolt\WorkObjects\Comment;
use Thunder\Bolt\WorkObjects\CommentPage;

interface CommentProvider
{
    public function page(CommentQuery $query): CommentPage;

    public function post(CommentWrite $write): Comment;

    public function edit(CommentWrite $write): Comment;

    public function delete(CommentDelete $delete): void;
}
