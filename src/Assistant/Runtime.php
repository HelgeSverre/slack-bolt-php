<?php

declare(strict_types=1);

namespace Thunder\Bolt\Assistant;

use Closure;
use Tempest\Container\Container;
use Thunder\Bolt\Assistant;
use Thunder\Bolt\Context;
use Thunder\Bolt\Errors\FailureStage;
use Thunder\Bolt\Invocation\Delivery;
use Thunder\Bolt\Invocation\HandlerInvoker;
use Thunder\Bolt\Invocation\InvocationFailure;
use Thunder\Bolt\Invocation\InvocationPipeline;
use Thunder\Bolt\Invocation\InvocationScope;
use Thunder\Bolt\Payloads\Events\AppContextChanged;
use Thunder\Bolt\Payloads\Events\AppHomeOpened;
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
        if ($this->assistant->agentView) {
            return $this->handleAgentView($delivery, $next);
        }
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
            jobs: $delivery->jobs,
            context: $delivery->context,
        );
        if ($handler === null) {
            $thread->saveContext();
            return null;
        }
        $scope = $scoped ? InvocationScope::create($this->container, $client, $authorization) : null;
        return $this->invoke($handler, $prepared, $scope);
    }

    private function handleAgentView(Delivery $delivery, Closure $next): mixed
    {
        $payload = $delivery->payload;
        if ($payload instanceof AppHomeOpened && $payload->tab === 'messages') {
            $handler = $this->assistant->opened;
            $context = $payload->appContext;
        } elseif ($payload instanceof AppContextChanged) {
            $handler = $this->assistant->appContextChanged;
            $context = $payload->context;
        } elseif ($this->isUserMessage($payload) && $payload instanceof Message) {
            $handler = $this->assistant->message;
            $context = $payload->appContext ?? AppContext::fromArray([]);
        } else {
            return $next();
        }
        if ($handler === null) {
            return $next();
        }
        $client = $delivery->client ?? throw new \LogicException('Agent delivery has no authorized client.');
        $authorization = $delivery->authorization ?? throw new \LogicException('Agent delivery has no authorization.');
        $thread = null;
        if ($payload instanceof Message) {
            $key = $delivery->thread?->key ?? throw new \LogicException('Agent message has no thread identity.');
            $thread = new Thread(
                $client,
                new ThreadIdentity($key, $payload->userId, $authorization->botUserId),
                $this->defaultStore,
                agentContext: $context,
            );
        }
        $prepared = new Delivery(
            $payload,
            $delivery->reply,
            $delivery->metadata,
            ListenerDescriptor::event(
                $payload instanceof Message ? 'message' : $payload->type,
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
            jobs: $delivery->jobs,
            context: $delivery->context,
        );
        $scope = InvocationScope::create($this->container, $client, $authorization);
        $scope->singleton(AppContext::class, $context);
        return $this->invoke($handler, $prepared, $scope);
    }

    private function invoke(Handler $handler, Delivery $prepared, ?Container $scope): mixed
    {
        $scope?->singleton(Context::class, $prepared->context);
        if ($scope !== null && $prepared->jobs !== null) {
            $scope->singleton(\Thunder\Bolt\Jobs::class, $prepared->jobs);
        }
        $stage = FailureStage::Middleware;
        $handlerFailure = null;
        try {
            return new InvocationPipeline()->run($handler->middleware, $prepared, function () use (
                $handler,
                $prepared,
                $scope,
                &$stage,
                &$handlerFailure,
            ): mixed {
                try {
                    return $this->invoker->invoke($handler->target, $prepared, $scope);
                } catch (\Throwable $error) {
                    $stage = FailureStage::Listener;
                    $handlerFailure = $error;
                    throw $error;
                }
            });
        } catch (\Throwable $error) {
            if ($stage === FailureStage::Listener && $handlerFailure !== $error) {
                $stage = FailureStage::Middleware;
            }
            throw new InvocationFailure($error, $prepared, $stage);
        }
    }

    private function isUserMessage(object $payload): bool
    {
        return (
            $payload instanceof Message
            && ($payload->raw['channel_type'] ?? null) === 'im'
            && $payload->channelId !== ''
            && $payload->userId !== ''
            && (
                $this->assistant->agentView
                    ? $payload->ts !== ''
                    : $payload->threadTs !== null
                    && $payload->threadTs !== ''
            )
            && !isset($payload->raw['bot_id'])
            && in_array($payload->raw['subtype'] ?? null, [null, 'file_share'], true)
        );
    }
}
