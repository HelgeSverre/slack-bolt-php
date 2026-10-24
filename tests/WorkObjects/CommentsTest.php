<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\WorkObjects;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Payloads\Action;
use Thunder\Bolt\Payloads\Events\EntityCommentsRequested;
use Thunder\Bolt\Payloads\View;
use Thunder\Bolt\Tests\Support\MemoryCommentProvider;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;
use Thunder\Bolt\WorkObjects\Actor;
use Thunder\Bolt\WorkObjects\Comment;
use Thunder\Bolt\WorkObjects\CommentBody;
use Thunder\Bolt\WorkObjects\CommentPage;
use Thunder\Bolt\WorkObjects\Comments\CommentOperation;
use Thunder\Bolt\WorkObjects\Comments\Comments;
use Thunder\Bolt\WorkObjects\ExternalReference;
use Thunder\Bolt\WorkObjects\Sender;

final class CommentsTest extends TestCase
{
    public function test_read_denial_does_not_query_the_provider_and_presents_an_empty_page(): void
    {
        $http = new RecordingHttpClient();
        $provider = new MemoryCommentProvider();
        $comments = $this->comments($http, $provider, static fn(): bool => false);

        $comments->present($this->request(), $this->actor());

        self::assertSame([], $provider->queries);
        self::assertSame('entity.presentComments', basename($http->requests[0]->getUri()->getPath()));
        self::assertSame(
            [
                'trigger_id' => 'TRIGGER-1',
                'comments' => [],
                'can_post_comment' => false,
                'delete_action_id' => 'delete-comment',
            ],
            $http->sentPayload(),
        );
    }

    public function test_present_derives_each_display_permission_from_the_policy(): void
    {
        $http = new RecordingHttpClient();
        $provider = new MemoryCommentProvider();
        $provider->nextPage = new CommentPage([
            Comment::make('COMMENT-1', Sender::slack('U-author'), CommentBody::text('First'), 100)->inThread(
                'thread-root',
            ),
        ], 'following-page');
        $calls = [];
        $comments = $this->comments($http, $provider, static function (
            Actor $actor,
            ExternalReference $reference,
            CommentOperation $operation,
            ?string $id,
        ) use (&$calls): bool {
            $calls[] = [$actor->userId, $reference->id, $operation->value, $id];

            return match ($operation) {
                CommentOperation::Read, CommentOperation::Post, CommentOperation::Edit => true,
                CommentOperation::Delete => false,
            };
        });

        $comments->present($this->request(), $this->actor());

        self::assertCount(1, $provider->queries);
        self::assertSame('thread-root', $provider->queries[0]->threadRootId);
        self::assertSame('next-page', $provider->queries[0]->cursor);
        self::assertSame(10, $provider->queries[0]->limit);
        self::assertSame(
            [
                'trigger_id' => 'TRIGGER-1',
                'comments' => [[
                    'can_edit' => true,
                    'can_delete' => false,
                    'can_reply' => true,
                    'id' => 'COMMENT-1',
                    'sender' => ['user_id' => 'U-author'],
                    'timestamp' => 100,
                    'comment' => ['value' => 'First'],
                    'thread_root_id' => 'thread-root',
                ]],
                'can_post_comment' => true,
                'delete_action_id' => 'delete-comment',
                'cursor' => 'following-page',
            ],
            $http->sentPayload(),
        );
        self::assertSame(
            [
                ['U-viewer', 'WORK-1', 'read',   null],
                ['U-viewer', 'WORK-1', 'post',   'thread-root'],
                ['U-viewer', 'WORK-1', 'edit',   'COMMENT-1'],
                ['U-viewer', 'WORK-1', 'delete', 'COMMENT-1'],
            ],
            $calls,
        );
    }

    public function test_submit_and_delete_do_not_touch_the_provider_when_policy_denies_them(): void
    {
        $http = new RecordingHttpClient();
        $provider = new MemoryCommentProvider();
        $comments = $this->comments($http, $provider, static fn(): bool => false);

        $comments->submit(View::fromArray($this->submission()), $this->actor(), 'delivery-post');
        $comments->delete(Action::fromArray($this->deleteAction()), $this->actor(), 'delivery-delete');

        self::assertSame([], $provider->posts);
        self::assertSame([], $provider->edits);
        self::assertSame([], $provider->deletes);
        self::assertSame(
            [
                'trigger_id' => 'TRIGGER-2',
                'error' => 'You do not have permission to change this comment.',
            ],
            $http->sentPayload(0),
        );
        self::assertSame(
            [
                'trigger_id' => 'TRIGGER-3',
                'error' => 'You do not have permission to delete this comment.',
            ],
            $http->sentPayload(1),
        );
    }

    public function test_provider_cannot_leak_a_comment_from_a_different_thread(): void
    {
        $http = new RecordingHttpClient();
        $provider = new MemoryCommentProvider();
        $provider->nextPage = new CommentPage([
            Comment::make('COMMENT-1', Sender::slack('U-author'), CommentBody::text('Wrong thread'), 100),
        ]);
        $comments = $this->comments($http, $provider, static fn(): bool => true);

        $this->expectException(\UnexpectedValueException::class);
        try {
            $comments->present($this->request(), $this->actor());
        } finally {
            self::assertCount(1, $provider->queries);
            self::assertSame([], $http->requests);
        }
    }

    public function test_submit_uses_rich_text_and_acknowledges_through_the_comment_api(): void
    {
        $http = new RecordingHttpClient();
        $provider = new MemoryCommentProvider();
        $provider->nextComment = Comment::make('COMMENT-2', Sender::slack('U-viewer'), CommentBody::text('Saved'), 101);
        $comments = $this->comments($http, $provider, static fn(): bool => true);

        $comments->submit(View::fromArray($this->submission()), $this->actor(), 'delivery-post');

        self::assertCount(1, $provider->posts);
        self::assertSame('rich_text', $provider->posts[0]->body->toArray()['blocks']['type']);
        self::assertSame('entity.acknowledgeCommentAction', basename($http->requests[0]->getUri()->getPath()));
        self::assertSame(
            [
                'trigger_id' => 'TRIGGER-2',
                'comment' => $provider->nextComment->permissions(true, true, true)->toArray(),
            ],
            $http->sentPayload(),
        );
    }

    public function test_edit_authorizes_the_existing_comment_and_uses_the_edit_provider_operation(): void
    {
        $http = new RecordingHttpClient();
        $provider = new MemoryCommentProvider();
        $provider->nextComment = Comment::make(
            'COMMENT-1',
            Sender::slack('U-viewer'),
            CommentBody::text('Edited'),
            102,
        );
        $calls = [];
        $comments = $this->comments($http, $provider, static function (
            Actor $_actor,
            ExternalReference $_reference,
            CommentOperation $operation,
            ?string $id,
        ) use (&$calls): bool {
            $calls[] = [$operation, $id];

            return $operation === CommentOperation::Edit && $id === 'COMMENT-1';
        });
        $payload = $this->submission();
        $payload['view']['callback_id'] = 'work-object-edit-comment';
        $payload['view']['external_id'] = 'COMMENT-1';

        $comments->submit(View::fromArray($payload), $this->actor(), 'delivery-edit');

        self::assertSame(
            [
                [CommentOperation::Edit,   'COMMENT-1'],
                [CommentOperation::Edit,   'COMMENT-1'],
                [CommentOperation::Delete, 'COMMENT-1'],
                [CommentOperation::Post,   'COMMENT-1'],
            ],
            $calls,
        );
        self::assertCount(1, $provider->edits);
        self::assertSame([], $provider->posts);
        self::assertSame('COMMENT-1', $provider->edits[0]->commentId);
        self::assertSame(
            [
                'trigger_id' => 'TRIGGER-2',
                'comment' => $provider->nextComment->permissions(true, false, false)->toArray(),
            ],
            $http->sentPayload(),
        );
    }

    public function test_provider_failure_is_sanitized_before_the_comment_action_api_is_called(): void
    {
        $http = new RecordingHttpClient();
        $provider = new MemoryCommentProvider();
        $comments = $this->comments($http, $provider, static fn(): bool => true);

        $comments->submit(View::fromArray($this->submission()), $this->actor(), 'delivery-post');

        self::assertCount(1, $provider->posts);
        self::assertSame(
            [
                'trigger_id' => 'TRIGGER-2',
                'error' => 'The comment could not be saved. Try again.',
            ],
            $http->sentPayload(),
        );
    }

    public function test_malformed_submission_with_a_valid_trigger_is_safely_rejected_without_writing(): void
    {
        $http = new RecordingHttpClient();
        $provider = new MemoryCommentProvider();
        $comments = $this->comments($http, $provider, static fn(): bool => true);
        $payload = $this->submission();
        $payload['view']['state']['values']['comment_input_block']['comment_input_action'] = [];

        $comments->submit(View::fromArray($payload), $this->actor(), 'delivery-invalid');

        self::assertSame([], $provider->posts);
        self::assertSame([], $provider->edits);
        self::assertSame(
            [
                'trigger_id' => 'TRIGGER-2',
                'error' => 'The comment input was invalid. Try again.',
            ],
            $http->sentPayload(),
        );
    }

    private function comments(RecordingHttpClient $http, MemoryCommentProvider $provider, callable $policy): Comments
    {
        return new Comments(new Client($http, 'xoxb-test'), $provider, $policy, 'delete-comment');
    }

    private function actor(): Actor
    {
        return new Actor('T-1', 'U-viewer');
    }

    private function request(): EntityCommentsRequested
    {
        return new EntityCommentsRequested([
            'type' => 'entity_comments_requested',
            'trigger_id' => 'TRIGGER-1',
            'external_ref' => ['id' => 'WORK-1', 'type' => 'work-item'],
            'thread_root_id' => 'thread-root',
            'cursor' => 'next-page',
            'limit' => 10,
        ]);
    }

    /** @return array<string, mixed> */
    private function submission(): array
    {
        return [
            'type' => 'view_submission',
            'trigger_id' => 'TRIGGER-2',
            'user' => ['id' => 'U-viewer'],
            'view' => [
                'id' => 'VIEW-1',
                'type' => 'entity_comment',
                'callback_id' => 'work-object-post-comment',
                'external_ref' => ['id' => 'WORK-1', 'type' => 'work-item'],
                'thread_root_id' => 'thread-root',
                'state' => [
                    'values' => [
                        'comment_input_block' => [
                            'comment_input_action' => [
                                'rich_text_value' => [
                                    'type' => 'rich_text',
                                    'elements' => [[
                                        'type' => 'rich_text_section',
                                        'elements' => [['type' => 'text', 'text' => 'Hello']],
                                    ]],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function deleteAction(): array
    {
        return [
            'type' => 'block_actions',
            'trigger_id' => 'TRIGGER-3',
            'user' => ['id' => 'U-viewer'],
            'actions' => [['action_id' => 'delete-comment', 'value' => 'COMMENT-1']],
            'container' => ['type' => 'entity_comment', 'external_ref' => ['id' => 'WORK-1', 'type' => 'work-item']],
        ];
    }
}
