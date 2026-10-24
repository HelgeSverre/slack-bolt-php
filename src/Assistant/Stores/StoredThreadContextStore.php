<?php

declare(strict_types=1);

namespace Thunder\Bolt\Assistant\Stores;

use Thunder\Bolt\Assistant\ThreadContext;
use Thunder\Bolt\Assistant\ThreadContextStore;
use Thunder\Bolt\Assistant\ThreadIdentity;
use Thunder\Bolt\Conversation\ConversationStore;
use Thunder\Bolt\Conversation\Exceptions\ConversationExpired;
use Thunder\Bolt\Conversation\Exceptions\ConversationNotFound;
use Thunder\Bolt\WebApi\Client;

final readonly class StoredThreadContextStore implements ThreadContextStore
{
    public function __construct(
        private ConversationStore $store,
    ) {}

    public function get(ThreadIdentity $identity, Client $client): ?ThreadContext
    {
        try {
            $value = $this->store->get($identity->storageKey())->value;
        } catch (ConversationNotFound|ConversationExpired) {
            return null;
        }
        if (!is_array($value) || $value !== [] && array_is_list($value)) {
            throw new \UnexpectedValueException('Persisted assistant context must be an object.');
        }

        return ThreadContext::fromArray($value);
    }

    public function save(ThreadIdentity $identity, ThreadContext $context, Client $client): void
    {
        $this->store->set($identity->storageKey(), $context->toArray());
    }
}
