<?php

declare(strict_types=1);

namespace App\Listeners;

use Thunder\Bolt\Attributes\Event;
use Thunder\Bolt\Conversation\ConversationContext;
use Thunder\Bolt\Conversation\ThreadContext;
use Thunder\Bolt\Payloads\Events\AppMention;

final class RememberConversation
{
    #[Event('app_mention')]
    public function __invoke(AppMention $event, ConversationContext $conversation, ThreadContext $thread): void
    {
        if (!$conversation->isAvailable()) {
            return;
        }

        $conversation->update(static fn(mixed $value): array => [
            'mentions' => self::counter($value) + 1,
            'last_user_id' => $event->userId,
        ]);

        if ($thread->isAvailable()) {
            $thread->update(static fn(mixed $value): array => [
                'mentions' => self::counter($value) + 1,
            ]);
        }
    }

    private static function counter(mixed $value): int
    {
        if (!is_array($value) || !is_int($value['mentions'] ?? null)) {
            return 0;
        }

        return $value['mentions'];
    }
}
