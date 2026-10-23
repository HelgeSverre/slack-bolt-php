<?php

declare(strict_types=1);

namespace Thunder\Bolt\Conversation;

use Closure;

interface ConversationStore
{
    public function get(ConversationKey $key): ConversationRecord;

    public function set(ConversationKey $key, mixed $value, ?int $expiresAtMilliseconds = null): void;

    public function delete(ConversationKey $key): void;

    /** @param Closure(mixed): mixed $updater */
    public function update(
        ConversationKey $key,
        Closure $updater,
        ?int $expiresAtMilliseconds = null,
    ): ConversationRecord;
}
