<?php

declare(strict_types=1);

namespace Thunder\Bolt\WorkObjects;

use Thunder\Bolt\WebApi\Client;
use Thunder\Bolt\WebApi\Response;

final readonly class Publisher
{
    public function __construct(
        private Client $client,
    ) {}

    public function unfurl(string $channel, string $timestamp, Entity ...$entities): Response
    {
        foreach ($entities as $entity) {
            if (!isset($entity->toArray()['app_unfurl_url'])) {
                throw new \InvalidArgumentException('An unfurl needs the original shared URL.');
            }
        }
        return $this->client
            ->chat()
            ->unfurl([
                'channel' => $channel,
                'ts' => $timestamp,
                'metadata' => EntityMetadata::make(...$entities)->toArray(),
            ]);
    }

    public function details(string $triggerId, Entity $entity): Response
    {
        Guard::text($triggerId);
        return $this->client->entity()->presentDetails(['trigger_id' => $triggerId, 'metadata' => $entity->details()]);
    }

    public function comments(
        string $triggerId,
        CommentPage $page,
        bool $canPost = false,
        string $deleteActionId = 'work_object_delete_comment',
    ): Response {
        Guard::text($triggerId);
        Guard::text($deleteActionId, 255);
        return $this->client
            ->entity()
            ->presentComments([
                'trigger_id' => $triggerId,
                'comments' => array_map(static fn(Comment $comment): array => $comment->toArray(), $page->comments),
                'can_post_comment' => $canPost,
                'delete_action_id' => $deleteActionId,
                ...($page->cursor !== null ? ['cursor' => $page->cursor] : []),
            ]);
    }

    public function commentSaved(string $triggerId, ?Comment $comment = null): Response
    {
        Guard::text($triggerId);
        return $this->client
            ->entity()
            ->acknowledgeCommentAction([
                'trigger_id' => $triggerId,
                ...($comment !== null ? ['comment' => $comment->toArray()] : []),
            ]);
    }

    public function commentError(string $triggerId, string $message): Response
    {
        Guard::text($triggerId);
        Guard::text($message);
        return $this->client->entity()->acknowledgeCommentAction(['trigger_id' => $triggerId, 'error' => $message]);
    }

    public function requireAuthentication(string $triggerId, string $url, bool $comments = false): Response
    {
        Guard::text($triggerId);
        Guard::url($url);
        return $this->client->call($comments ? 'entity.presentComments' : 'entity.presentDetails', [
            'trigger_id' => $triggerId,
            'user_auth_required' => true,
            'user_auth_url' => $url,
        ]);
    }
}
