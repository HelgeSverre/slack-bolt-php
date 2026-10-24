<?php

declare(strict_types=1);

namespace Thunder\Bolt\WorkObjects\Comments;

use Thunder\Bolt\Payloads\Action;
use Thunder\Bolt\Payloads\Events\EntityCommentsRequested;
use Thunder\Bolt\Payloads\View;
use Thunder\Bolt\WebApi\Client;
use Thunder\Bolt\WorkObjects\Actor;
use Thunder\Bolt\WorkObjects\Comment;
use Thunder\Bolt\WorkObjects\CommentPage;
use Thunder\Bolt\WorkObjects\CommentSubmission;
use Thunder\Bolt\WorkObjects\ExternalReference;
use Thunder\Bolt\WorkObjects\Publisher;

final readonly class Comments
{
    private \Closure $authorize;
    private Publisher $publisher;

    /** @param callable(Actor, ExternalReference, CommentOperation, ?string): bool $authorize */
    public function __construct(
        Client $client,
        private CommentProvider $provider,
        callable $authorize,
        private string $deleteActionId = 'work_object_delete_comment',
    ) {
        $this->authorize = \Closure::fromCallable($authorize);
        $this->publisher = $client->workObjects();
    }

    public function present(EntityCommentsRequested $event, Actor $actor): void
    {
        if (!$this->allowed($actor, $event->reference, CommentOperation::Read)) {
            $this->publisher->comments($event->triggerId, new CommentPage(), false, $this->deleteActionId);
            return;
        }
        $page = $this->provider->page(
            new CommentQuery($actor, $event->reference, $event->threadRootId, $event->cursor, $event->limit),
        );
        $post = $this->allowed($actor, $event->reference, CommentOperation::Post, $event->threadRootId);
        $comments = [];
        foreach ($page->comments as $comment) {
            if ($comment->threadRootId !== $event->threadRootId) {
                throw new \UnexpectedValueException('The comment provider returned a different thread.');
            }
            $comments[] = $comment->permissions(
                $this->allowed($actor, $event->reference, CommentOperation::Edit, $comment->id),
                $this->allowed($actor, $event->reference, CommentOperation::Delete, $comment->id),
                $post,
            );
        }
        $this->publisher->comments(
            $event->triggerId,
            new CommentPage($comments, $page->cursor),
            $post,
            $this->deleteActionId,
        );
    }

    public function submit(View $view, Actor $actor, string $deliveryId): void
    {
        try {
            $submission = CommentSubmission::fromView($view);
        } catch (\InvalidArgumentException $exception) {
            $trigger = $view->raw['trigger_id'] ?? null;
            if (!is_string($trigger) || $trigger === '') {
                throw $exception;
            }
            $this->publisher->commentError($trigger, 'The comment input was invalid. Try again.');
            return;
        }
        $operation = $submission->commentId === null ? CommentOperation::Post : CommentOperation::Edit;
        if (!$this->allowed(
            $actor,
            $submission->reference,
            $operation,
            $submission->commentId ?? $submission->threadRootId,
        )) {
            $this->publisher->commentError(
                $submission->triggerId,
                'You do not have permission to change this comment.',
            );
            return;
        }
        try {
            $write = new CommentWrite(
                $actor,
                $submission->reference,
                $submission->body,
                $deliveryId,
                $submission->commentId,
                $submission->threadRootId,
            );
            $comment = $operation === CommentOperation::Post
                ? $this->provider->post($write)
                : $this->provider->edit($write);
        } catch (\Throwable) {
            $this->publisher->commentError($submission->triggerId, 'The comment could not be saved. Try again.');
            return;
        }
        $this->publisher->commentSaved(
            $submission->triggerId,
            $comment->permissions(
                $this->allowed($actor, $submission->reference, CommentOperation::Edit, $comment->id),
                $this->allowed($actor, $submission->reference, CommentOperation::Delete, $comment->id),
                $this->allowed(
                    $actor,
                    $submission->reference,
                    CommentOperation::Post,
                    $comment->threadRootId ?? $comment->id,
                ),
            ),
        );
    }

    public function delete(Action $action, Actor $actor, string $deliveryId): void
    {
        $container = $action->raw['container'] ?? [];
        if (
            $action->actionId !== $this->deleteActionId
            || !is_array($container)
            || ($container['type'] ?? '') !== 'entity_comment'
            || !is_array($container['external_ref'] ?? null)
            || !is_string($action->action['value'] ?? null)
            || $action->triggerId === null
        ) {
            throw new \InvalidArgumentException('Expected a Work Object comment deletion.');
        }
        $reference = ExternalReference::fromArray($container['external_ref']);
        $id = $action->action['value'];
        if (!$this->allowed($actor, $reference, CommentOperation::Delete, $id)) {
            $this->publisher->commentError($action->triggerId, 'You do not have permission to delete this comment.');
            return;
        }
        try {
            $this->provider->delete(new CommentDelete($actor, $reference, $id, $deliveryId));
        } catch (\Throwable) {
            $this->publisher->commentError($action->triggerId, 'The comment could not be deleted. Try again.');
            return;
        }
        $this->publisher->commentSaved($action->triggerId);
    }

    private function allowed(
        Actor $actor,
        ExternalReference $reference,
        CommentOperation $operation,
        ?string $id = null,
    ): bool {
        try {
            return ($this->authorize)($actor, $reference, $operation, $id) === true;
        } catch (\Throwable) {
            return false;
        }
    }
}
