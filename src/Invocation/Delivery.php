<?php

declare(strict_types=1);

namespace Thunder\Bolt\Invocation;

use Thunder\Bolt\Authorization\Authorization;
use Thunder\Bolt\Authorization\AuthorizationSource;
use Thunder\Bolt\Conversation\ConversationContext;
use Thunder\Bolt\Conversation\ThreadContext;
use Thunder\Bolt\Reply;
use Thunder\Bolt\Routing\ListenerDescriptor;
use Thunder\Bolt\WebApi\Client;

final readonly class Delivery
{
    /**
     * @param  array<array-key, mixed>  $metadata
     * @param  array<int|string, string>  $matches
     * @param  array<string, array<int|string, string>>  $constraintMatches
     */
    public function __construct(
        public object $payload,
        public Reply $reply,
        public array $metadata,
        public ?ListenerDescriptor $listener,
        public array $matches = [],
        public array $constraintMatches = [],
        public ?Client $client = null,
        public ?Authorization $authorization = null,
        public ?AuthorizationSource $authorizationSource = null,
        public ?ConversationContext $conversation = null,
        public ?ThreadContext $thread = null,
        public ?\Thunder\Bolt\Assistant\Thread $assistant = null,
        public ?\Thunder\Bolt\Functions\Execution $execution = null,
    ) {}
}
