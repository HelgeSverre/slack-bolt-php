<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Support;

use Thunder\Bolt\WorkObjects\Comment;
use Thunder\Bolt\WorkObjects\CommentPage;
use Thunder\Bolt\WorkObjects\Comments\CommentDelete;
use Thunder\Bolt\WorkObjects\Comments\CommentProvider;
use Thunder\Bolt\WorkObjects\Comments\CommentQuery;
use Thunder\Bolt\WorkObjects\Comments\CommentWrite;

final class MemoryCommentProvider implements CommentProvider
{
    /** @var list<CommentQuery> */
    public array $queries = [];

    /** @var list<CommentWrite> */
    public array $posts = [];

    /** @var list<CommentWrite> */
    public array $edits = [];

    /** @var list<CommentDelete> */
    public array $deletes = [];

    public CommentPage $nextPage;

    public ?Comment $nextComment = null;

    public function __construct()
    {
        $this->nextPage = new CommentPage();
    }

    public function page(CommentQuery $query): CommentPage
    {
        $this->queries[] = $query;

        return $this->nextPage;
    }

    public function post(CommentWrite $write): Comment
    {
        $this->posts[] = $write;

        return $this->nextComment ?? throw new \LogicException('Configure the next comment.');
    }

    public function edit(CommentWrite $write): Comment
    {
        $this->edits[] = $write;

        return $this->nextComment ?? throw new \LogicException('Configure the next comment.');
    }

    public function delete(CommentDelete $delete): void
    {
        $this->deletes[] = $delete;
    }
}
