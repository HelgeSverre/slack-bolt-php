<?php

declare(strict_types=1);

namespace Thunder\Bolt\WorkObjects;

use Thunder\Bolt\Payloads\View;

final readonly class CommentSubmission
{
    private function __construct(
        public string $triggerId,
        public ExternalReference $reference,
        public CommentBody $body,
        public ?string $commentId,
        public ?string $threadRootId,
    ) {}

    public static function fromView(View $view): self
    {
        if (
            $view->type !== 'view_submission'
            || ($view->view['type'] ?? '') !== 'entity_comment'
            || !in_array($view->callbackId, ['work-object-post-comment', 'work-object-edit-comment'], true)
        ) {
            throw new \InvalidArgumentException('Expected a Work Object comment submission.');
        }
        $trigger = $view->raw['trigger_id'] ?? null;
        $ref = $view->view['external_ref'] ?? null;
        $cell = $view->state('comment_input_block', 'comment_input_action');
        if (!is_string($trigger) || !is_array($ref) || !is_array($cell)) {
            throw new \InvalidArgumentException('Incomplete comment submission.');
        }
        $rich = $cell['rich_text_value'] ?? null;
        $body = is_array($rich)
            ? CommentBody::fromArray(['blocks' => $rich])
            : CommentBody::fromArray(['value' => $cell['value'] ?? null]);
        $commentId = $view->callbackId === 'work-object-edit-comment' ? $view->view['external_id'] ?? null : null;
        if (
            $commentId !== null && !is_string($commentId)
            || $view->callbackId === 'work-object-edit-comment' && ($commentId === null || $commentId === '')
        ) {
            throw new \InvalidArgumentException('An edit requires the persisted comment ID.');
        }
        $root = $view->view['thread_root_id'] ?? null;
        if ($root !== null && !is_string($root)) {
            throw new \InvalidArgumentException('Invalid thread root.');
        }
        Guard::text($trigger);
        return new self($trigger, ExternalReference::fromArray($ref), $body, $commentId, $root);
    }
}
