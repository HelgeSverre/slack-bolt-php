<?php

declare(strict_types=1);

namespace App\Demo;

use Thunder\Bolt\Conversation\ConversationKey;
use Thunder\Bolt\Conversation\Exceptions\ConversationNotFound;
use Thunder\Bolt\Conversation\Stores\FileConversationStore;

final readonly class ThreadState
{
    private FileConversationStore $store;

    public function __construct(string $directory)
    {
        $this->store = new FileConversationStore($directory . '/threads');
    }

    public static function id(string $team, string $channel, string $thread): string
    {
        return hash('sha256', json_encode([$team, $channel, $thread], JSON_THROW_ON_ERROR));
    }

    public function get(string $team, string $channel, string $thread): ?array
    {
        try {
            $value = $this->store->get(new ConversationKey($channel, teamId: $team, threadTs: $thread))->value;
            return is_array($value) ? $value : null;
        } catch (ConversationNotFound) {
            return null;
        }
    }

    public function put(string $team, string $channel, string $thread, array $value): void
    {
        $this->store->set(new ConversationKey($channel, teamId: $team, threadTs: $thread), $value);
    }

    /** @param \Closure(array): array $updater */
    public function update(string $team, string $channel, string $thread, \Closure $updater): array
    {
        $record = $this->store->update(
            new ConversationKey($channel, teamId: $team, threadTs: $thread),
            static function (mixed $current) use ($updater): array {
                $value = $updater(is_array($current) ? $current : []);
                if (!is_array($value)) {
                    throw new \UnexpectedValueException('AI thread updates must return an object.');
                }
                return $value;
            },
        );
        return is_array($record->value)
            ? $record->value
            : throw new \UnexpectedValueException('AI thread state must be an object.');
    }
}
