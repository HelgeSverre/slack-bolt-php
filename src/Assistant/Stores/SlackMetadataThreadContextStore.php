<?php

declare(strict_types=1);

namespace Thunder\Bolt\Assistant\Stores;

use Thunder\Bolt\Assistant\ThreadContext;
use Thunder\Bolt\Assistant\ThreadContextStore;
use Thunder\Bolt\Assistant\ThreadIdentity;
use Thunder\Bolt\WebApi\Client;

/**
 * Compatibility adapter for assistants that deliberately keep Slack focus context in a bot reply.
 *
 * It has no process-local cache: every read is scoped to one trusted thread identity.
 */
final class SlackMetadataThreadContextStore implements ThreadContextStore
{
    public function get(ThreadIdentity $identity, Client $client): ?ThreadContext
    {
        $message = $this->initialBotMessage($identity, $client);
        if ($message === null) {
            return null;
        }
        $metadata = $message['metadata'] ?? null;
        if (!is_array($metadata) || ($metadata['event_type'] ?? null) !== 'assistant_thread_context') {
            return null;
        }
        $context = $metadata['event_payload'] ?? null;
        if (!is_array($context) || $context !== [] && array_is_list($context)) {
            throw new \UnexpectedValueException('Assistant context metadata must be an object.');
        }

        return ThreadContext::fromArray($context);
    }

    public function save(ThreadIdentity $identity, ThreadContext $context, Client $client): void
    {
        $message = $this->initialBotMessage($identity, $client);
        if ($message === null) {
            throw new \LogicException('Slack did not return an assistant bot message for context storage.');
        }
        $metadata = $message['metadata'] ?? null;
        if (
            is_array($metadata)
            && isset($metadata['event_type'])
            && $metadata['event_type'] !== 'assistant_thread_context'
        ) {
            throw new \LogicException('The assistant message has unrelated metadata that cannot be replaced.');
        }

        $text = $message['text'] ?? '';
        if (!is_string($text)) {
            throw new \UnexpectedValueException('Slack returned an assistant root with invalid text.');
        }
        $arguments = [
            'channel' => $identity->channelId(),
            'ts' => $message['ts'],
            'text' => $text,
            'metadata' => [
                'event_type' => 'assistant_thread_context',
                'event_payload' => $context->toArray() === [] ? new \stdClass() : $context->toArray(),
            ],
        ];
        if (is_array($message['blocks'] ?? null)) {
            $arguments['blocks'] = $message['blocks'];
        }
        $client->chat()->update($arguments);
    }

    private function initialBotMessage(ThreadIdentity $identity, Client $client): ?array
    {
        $bot = $identity->botUserId;
        if ($bot === null || $bot === '') {
            throw new \LogicException('Slack metadata context storage requires the authenticated bot user ID.');
        }

        $cursor = null;
        $seen = [];
        while (true) {
            $arguments = [
                'channel' => $identity->channelId(),
                'ts' => $identity->threadTs(),
                'oldest' => $identity->threadTs(),
                'inclusive' => true,
                'include_all_metadata' => true,
                'limit' => 100,
            ];
            if ($cursor !== null) {
                $arguments['cursor'] = $cursor;
            }
            $response = $client->conversations()->replies($arguments);
            $messages = $response->data['messages'] ?? [];
            if (!is_array($messages)) {
                throw new \UnexpectedValueException('Slack returned invalid thread messages.');
            }
            foreach ($messages as $message) {
                if (
                    is_array($message)
                    && ($message['user'] ?? null) === $bot
                    && !isset($message['subtype'])
                    && is_string($message['ts'] ?? null)
                ) {
                    return $message;
                }
            }
            $next = $response->data['response_metadata']['next_cursor'] ?? null;
            if (!is_string($next) || $next === '') {
                return null;
            }
            if (isset($seen[$next])) {
                throw new \UnexpectedValueException('Slack repeated an assistant context pagination cursor.');
            }
            $seen[$next] = true;
            $cursor = $next;
        }
    }
}
