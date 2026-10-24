<?php

declare(strict_types=1);

namespace Thunder\Bolt\Assistant;

use Closure;
use Tempest\Container\Container;
use Thunder\Bolt\Assistant;
use Thunder\Bolt\Invocation\Delivery;
use Thunder\Bolt\Invocation\HandlerInvoker;
use Thunder\Bolt\Invocation\InvocationPipeline;
use Thunder\Bolt\Invocation\InvocationScope;
use Thunder\Bolt\Payloads\Events\AssistantThreadContextChanged;
use Thunder\Bolt\Payloads\Events\AssistantThreadEvent;
use Thunder\Bolt\Payloads\Events\AssistantThreadStarted;
use Thunder\Bolt\Payloads\Events\Message;
use Thunder\Bolt\Routing\ListenerDescriptor;

/** @internal Runs assistant handlers inside the application's authorized delivery pipeline. */
final readonly class Runtime
{
    public function __construct(
        private Assistant $assistant,
        private Container $container,
        private HandlerInvoker $invoker,
        private ThreadContextStore $defaultStore,
    ) {}

    public function handle(Delivery $delivery, Closure $next, bool $scoped): mixed
    {
        $payload = $delivery->payload;
        if (!$payload instanceof AssistantThreadEvent && !$this->isUserMessage($payload)) {
            return $next();
        }
        $key = $delivery->thread?->key ?? throw new \LogicException('Assistant delivery has no thread identity.');
        $client = $delivery->client ?? throw new \LogicException('Assistant delivery has no authorized client.');
        $authorization = $delivery->authorization ?? throw new \LogicException(
            'Assistant delivery has no authorization.',
        );
        $userId = $payload instanceof AssistantThreadEvent || $payload instanceof Message ? $payload->userId : null;
        if (!is_string($userId) || $userId === '') {
            throw new \InvalidArgumentException('Assistant delivery has no user identity.');
        }
        $handler = match (true) {
            $payload instanceof AssistantThreadStarted => $this->assistant->started,
            $payload instanceof AssistantThreadContextChanged => $this->assistant->contextChanged,
            default => $this->assistant->message,
        };
        $thread = new Thread(
            $client,
            new ThreadIdentity($key, $userId, $authorization->botUserId),
            $this->assistant->store ?? $this->defaultStore,
            $payload instanceof AssistantThreadEvent ? $payload->context : null,
        );
        $prepared = new Delivery(
            $payload,
            $delivery->reply,
            $delivery->metadata,
            $handler === null
                ? null
                : ListenerDescriptor::event(
                    $payload instanceof AssistantThreadEvent ? $payload->type : 'message',
                    $handler->target,
                    $handler->middleware,
                    source: 'Assistant configuration',
                ),
            $delivery->matches,
            $delivery->constraintMatches,
            $client,
            $authorization,
            $delivery->authorizationSource,
            $delivery->conversation,
            $delivery->thread,
            $thread,
        );
        if ($handler === null) {
            $thread->saveContext();
            return null;
        }
        $scope = $scoped ? InvocationScope::create($this->container, $client, $authorization) : null;
        return new InvocationPipeline()->run($handler->middleware, $prepared, fn(): mixed => $this->invoker->invoke(
            $handler->target,
            $prepared,
            $scope,
        ));
    }

    private function isUserMessage(object $payload): bool
    {
        return (
            $payload instanceof Message
            && ($payload->raw['channel_type'] ?? null) === 'im'
            && $payload->channelId !== ''
            && $payload->userId !== ''
            && $payload->threadTs !== null
            && $payload->threadTs !== ''
            && !isset($payload->raw['bot_id'])
            && in_array($payload->raw['subtype'] ?? null, [null, 'file_share'], true)
        );
    }
}
