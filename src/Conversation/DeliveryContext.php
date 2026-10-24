<?php

declare(strict_types=1);

namespace Thunder\Bolt\Conversation;

use Thunder\Bolt\Authorization\Authorization;
use Thunder\Bolt\Authorization\AuthorizationSource;

/**
 * Derives durable conversation and thread identities from a trusted Slack delivery.
 *
 * The identities are intentionally absent when Slack did not identify a conversation
 * or a known thread root. Callers can then expose an unavailable context instead of
 * guessing from incidental interaction fields.
 */
final readonly class DeliveryContext
{
    public function __construct(
        public ?ConversationKey $conversation,
        public ?ConversationKey $thread,
    ) {}

    public static function fromArray(array $body, AuthorizationSource $source, Authorization $authorization): self
    {
        if (in_array($body['type'] ?? null, ['shortcut', 'view_submission', 'view_closed'], true)) {
            return new self(null, null);
        }

        $conversation = self::conversation($source, $authorization);

        return new self($conversation, self::thread($body, $conversation));
    }

    private static function conversation(AuthorizationSource $source, Authorization $authorization): ?ConversationKey
    {
        if ($source->conversationId === null) {
            return null;
        }

        $isEnterpriseInstall = $authorization->isEnterpriseInstall || $source->isEnterpriseInstall;
        $enterpriseId = $authorization->enterpriseId ?? $source->enterpriseId;
        if ($isEnterpriseInstall && $enterpriseId === null) {
            return null;
        }

        return new ConversationKey(
            conversationId: $source->conversationId,
            // Enterprise installations are partitioned by the organization and
            // conversation only. A contextual workspace is useful to handlers
            // through AuthorizationSource, but would split durable shared state.
            teamId: $isEnterpriseInstall ? null : $authorization->teamId ?? $source->teamId,
            enterpriseId: $enterpriseId,
            isEnterpriseInstall: $isEnterpriseInstall,
        );
    }

    private static function thread(array $body, ?ConversationKey $conversation): ?ConversationKey
    {
        if ($conversation === null) {
            return null;
        }

        $threadTs = self::eventThreadTs($body) ?? self::interactionThreadTs($body);

        return $threadTs === null ? null : $conversation->thread($threadTs);
    }

    private static function eventThreadTs(array $body): ?string
    {
        $event = is_array($body['event'] ?? null) ? $body['event'] : $body;
        $type = self::string($event['type'] ?? null);
        if ($type === null) {
            return null;
        }
        if (in_array($type, ['assistant_thread_started', 'assistant_thread_context_changed'], true)) {
            return self::string(self::array($event['assistant_thread'] ?? null)['thread_ts'] ?? null);
        }

        if (in_array($type, ['app_mention', 'message'], true)) {
            $subtype = self::string($event['subtype'] ?? null);
            if (in_array($subtype, ['message_changed', 'message_replied'], true)) {
                $message = self::array($event['message'] ?? null);

                return self::string($message['thread_ts'] ?? null) ?? self::string($message['ts'] ?? null);
            }
            if ($subtype === 'message_deleted') {
                $message = self::array($event['previous_message'] ?? null);

                return self::string($message['thread_ts'] ?? null) ?? self::string($message['ts'] ?? null);
            }

            return self::string($event['thread_ts'] ?? null) ?? self::string($event['ts'] ?? null);
        }

        // A reaction's item timestamp might be a reply timestamp. Only Slack's
        // explicit thread root is safe to turn into thread-scoped storage.
        if (in_array($type, ['reaction_added', 'reaction_removed'], true)) {
            return self::string($event['thread_ts'] ?? null);
        }

        return null;
    }

    private static function interactionThreadTs(array $body): ?string
    {
        if (!in_array($body['type'] ?? null, ['block_actions', 'interactive_message', 'message_action'], true)) {
            return null;
        }

        $message = self::array($body['message'] ?? null);
        $threadTs =
            self::string($message['thread_ts'] ?? null)
            ?? self::string($message['ts'] ?? null)
            ?? self::string($body['message_ts'] ?? null);
        if ($threadTs !== null) {
            return $threadTs;
        }

        $container = self::array($body['container'] ?? null);

        return self::string($container['thread_ts'] ?? null) ?? self::string($container['message_ts'] ?? null);
    }

    private static function array(mixed $value): array
    {
        return is_array($value) && !array_is_list($value) ? $value : [];
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
