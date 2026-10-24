<?php

declare(strict_types=1);

namespace Thunder\Bolt\WorkObjects\Comments;

enum CommentOperation: string
{
    case Read = 'read';
    case Post = 'post';
    case Edit = 'edit';
    case Delete = 'delete';
}
