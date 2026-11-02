<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads;

/** Shared event catalog for decoding, listener validation, and generated handlers. */
final class EventPayloads
{
    /** @return class-string */
    public static function classFor(string $type): string
    {
        return match ($type) {
            'app_mention' => Events\AppMention::class,
            'message' => Events\Message::class,
            'function_executed' => Events\FunctionExecuted::class,
            'assistant_thread_started' => Events\AssistantThreadStarted::class,
            'assistant_thread_context_changed' => Events\AssistantThreadContextChanged::class,
            'entity_details_requested' => Events\EntityDetailsRequested::class,
            'entity_comments_requested' => Events\EntityCommentsRequested::class,
            'reaction_added' => Events\ReactionAdded::class,
            'reaction_removed' => Events\ReactionRemoved::class,
            'member_joined_channel' => Events\MemberJoinedChannel::class,
            'member_left_channel' => Events\MemberLeftChannel::class,
            'link_shared' => Events\LinkShared::class,
            'file_shared' => Events\FileShared::class,
            'app_uninstalled' => Events\AppUninstalled::class,
            'tokens_revoked' => Events\TokensRevoked::class,
            'agent_session_stopped' => Events\AgentSessionStopped::class,
            'agent_session_title_changed' => Events\AgentSessionTitleChanged::class,
            'app_home_opened' => Events\AppHomeOpened::class,
            'channel_created' => Events\ChannelCreated::class,
            'channel_rename' => Events\ChannelRenamed::class,
            'channel_archive' => Events\ChannelArchived::class,
            'channel_unarchive' => Events\ChannelUnarchived::class,
            'channel_deleted' => Events\ChannelDeleted::class,
            'message_metadata_posted' => Events\MessageMetadataPosted::class,
            'message_metadata_updated' => Events\MessageMetadataUpdated::class,
            'message_metadata_deleted' => Events\MessageMetadataDeleted::class,
            default => Event::class,
        };
    }

    public static function fromArray(array $event): object
    {
        $class = self::classFor(is_string($event['type'] ?? null) ? $event['type'] : '');
        $payload = $class::fromArray($event);
        if (!is_object($payload)) {
            throw new \LogicException('Event factories must return a payload object.');
        }
        return $payload;
    }
}
