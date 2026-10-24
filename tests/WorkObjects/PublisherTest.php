<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\WorkObjects;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;
use Thunder\Bolt\WorkObjects\Comment;
use Thunder\Bolt\WorkObjects\CommentBody;
use Thunder\Bolt\WorkObjects\CommentPage;
use Thunder\Bolt\WorkObjects\Entity;
use Thunder\Bolt\WorkObjects\Sender;

final class PublisherTest extends TestCase
{
    public function test_publisher_uses_the_entity_and_chat_endpoints_with_contract_payloads(): void
    {
        $http = new RecordingHttpClient();
        $publisher = new Client($http, 'xoxb-test')->workObjects();
        $entity = Entity::task('TASK-1', 'https://work.example.test/TASK-1', 'A task')->unfurlUrl(
            'https://work.example.test/TASK-1?shared=1',
        );
        $comment = Comment::make('C-1', Sender::slack('U-1'), CommentBody::markdown('*Done*'), 100);

        $publisher->details('TRIGGER-1', $entity);
        $publisher->unfurl('C-1', '1700000000.000001', $entity);
        $publisher->comments('TRIGGER-2', new CommentPage([$comment], 'next'), true, 'delete-comment');
        $publisher->commentSaved('TRIGGER-3', $comment);
        $publisher->commentError('TRIGGER-4', 'No permission');

        self::assertSame('entity.presentDetails', basename($http->requests[0]->getUri()->getPath()));
        self::assertSame($entity->details(), $http->sentPayload(0)['metadata']);
        self::assertSame('chat.unfurl', basename($http->requests[1]->getUri()->getPath()));
        self::assertSame(['entities' => [$entity->toArray()]], $http->sentPayload(1)['metadata']);
        self::assertSame('entity.presentComments', basename($http->requests[2]->getUri()->getPath()));
        self::assertSame(
            [
                'trigger_id' => 'TRIGGER-2',
                'comments' => [$comment->toArray()],
                'can_post_comment' => true,
                'delete_action_id' => 'delete-comment',
                'cursor' => 'next',
            ],
            $http->sentPayload(2),
        );
        self::assertSame('entity.acknowledgeCommentAction', basename($http->requests[3]->getUri()->getPath()));
        self::assertSame(['trigger_id' => 'TRIGGER-3', 'comment' => $comment->toArray()], $http->sentPayload(3));
        self::assertSame(['trigger_id' => 'TRIGGER-4', 'error' => 'No permission'], $http->sentPayload(4));
    }
}
