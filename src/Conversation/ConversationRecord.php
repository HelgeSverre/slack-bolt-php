<?php

declare(strict_types=1);

namespace Thunder\Bolt\Conversation;

final readonly class ConversationRecord
{
    public function __construct(
        public mixed $value,
        public ?int $expiresAtMilliseconds,
    ) {}
}
